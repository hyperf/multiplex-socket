<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Multiplex\Socket;

use Hyperf\Coordinator\Constants;
use Hyperf\Coordinator\CoordinatorManager;
use Hyperf\Coroutine\Locker;
use Hyperf\Engine\Channel;
use Hyperf\Engine\Exception\SocketConnectException;
use Hyperf\Engine\Socket;
use Multiplex\ChannelManager;
use Multiplex\Contract\ClientInterface;
use Multiplex\Contract\HasHeartbeatInterface;
use Multiplex\Contract\HasSerializerInterface;
use Multiplex\Contract\IdGeneratorInterface;
use Multiplex\Contract\PackerInterface;
use Multiplex\Contract\SerializerInterface;
use Multiplex\Exception\ChannelClosedException;
use Multiplex\Exception\ChannelLostException;
use Multiplex\Exception\ClientConnectFailedException;
use Multiplex\Exception\RecvTimeoutException;
use Multiplex\Exception\SendFailedException;
use Multiplex\IdGenerator;
use Multiplex\Packer;
use Multiplex\Packet;
use Multiplex\Serializer\StringSerializer;
use Psr\Log\LoggerInterface;
use Throwable;

use function Hyperf\Coroutine\go;

class Client implements ClientInterface, HasSerializerInterface
{
    protected PackerInterface $packer;

    protected SerializerInterface $serializer;

    protected IdGeneratorInterface $generator;

    protected ?Channel $chan = null;

    protected ?Socket $client = null;

    protected int $requests = 0;

    protected array $config = [
        'package_max_length' => 1024 * 1024 * 2,
        'recv_timeout' => 10,
        'connect_timeout' => 0.5,
        'heartbeat' => 20,
        'max_requests' => 0,
        'max_wait_close_seconds' => 2,
    ];

    protected ChannelManager $channelManager;

    protected bool $heartbeat = false;

    protected ?LoggerInterface $logger = null;

    protected ?Socket\SocketFactory $factory;

    public function __construct(
        protected string $name,
        protected int $port,
        ?IdGeneratorInterface $generator = null,
        ?SerializerInterface $serializer = null,
        ?PackerInterface $packer = null,
        ?Socket\SocketFactory $factory = null,
    ) {
        $this->packer = $packer ?? new Packer();
        $this->generator = $generator ?? new IdGenerator();
        $this->serializer = $serializer ?? new StringSerializer();
        $this->channelManager = new ChannelManager();
        $this->factory = $factory ?? new Socket\SocketFactory();
    }

    public function set(array $settings): static
    {
        $this->config = array_replace($this->config, $settings);
        return $this;
    }

    public function setLogger(?LoggerInterface $logger): static
    {
        $this->logger = $logger;
        return $this;
    }

    public function request(mixed $data): mixed
    {
        return $this->recv($this->send($data));
    }

    public function send(mixed $data): int
    {
        if ($this->config['max_requests'] > 0 && $this->requests >= $this->config['max_requests']) {
            $key = spl_object_hash($this);
            if (Locker::lock($key)) {
                try {
                    $this->waitUntilChannelManagerEmpty();
                    $this->close();
                } finally {
                    Locker::unlock($key);
                }
            }
        }

        $this->loop();

        $this->getChannelManager()->get($id = $this->generator->generate(), true);

        try {
            $payload = $this->packer->pack(
                new Packet(
                    $id,
                    $this->getSerializer()->serialize($data)
                )
            );

            $this->chan->push($payload);
        } catch (Throwable $exception) {
            is_int($id) && $this->getChannelManager()->close($id);
            throw $exception;
        }

        return $id;
    }

    public function recv(int $id): mixed
    {
        $this->loop();

        $manager = $this->getChannelManager();
        $chan = $manager->get($id);
        if ($chan === null) {
            throw new ChannelLostException();
        }

        try {
            $data = $chan->pop($timeout = $this->config['recv_timeout'] ?? 10);
            if ($chan->isTimeout()) {
                throw new RecvTimeoutException(sprintf('Recv channel [%d] pop timeout after %d seconds.', $id, $timeout));
            }

            if ($chan->isClosing()) {
                throw new ChannelClosedException(sprintf('Recv channel [%d] closed.', $id));
            }
        } finally {
            $manager->close($id);
        }

        return $data;
    }

    public function getSerializer(): SerializerInterface
    {
        return $this->serializer;
    }

    public function getChannelManager(): ChannelManager
    {
        return $this->channelManager;
    }

    public function close(): void
    {
        $this->chan?->close();
        $this->getChannelManager()->flush();
        $this->client?->close();
        $this->requests = 0;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    protected function makeClient(): Socket
    {
        try {
            return $this->factory->make(new Socket\SocketOption(
                $this->getName(),
                $this->getPort(),
                $this->config['connect_timeout'] ?? 0.5,
                [
                    'open_length_check' => true,
                    'package_length_type' => 'N',
                    'package_length_offset' => 0,
                    'package_body_offset' => 4,
                    'package_max_length' => $this->config['package_max_length'] ?? 1024 * 1024 * 2,
                ]
            ));
        } catch (SocketConnectException $exception) {
            $this->close();
            throw new ClientConnectFailedException($exception->getMessage(), $exception->getCode());
        }
    }

    protected function getHeartbeat()
    {
        return $this->config['heartbeat'] ?? null;
    }

    protected function getMaxIdleTime(): int
    {
        $heartbeat = $this->getHeartbeat();
        if (! is_numeric($heartbeat)) {
            return -1;
        }

        return (int) ($heartbeat * 2);
    }

    protected function heartbeat(): void
    {
        $heartbeat = $this->getHeartbeat();
        if (! $this->heartbeat && is_numeric($heartbeat)) {
            $this->heartbeat = true;

            go(function () use ($heartbeat) {
                try {
                    while (true) {
                        if (CoordinatorManager::until(Constants::WORKER_EXIT)->yield($heartbeat)) {
                            break;
                        }

                        try {
                            // PING
                            if ($chan = $this->chan and $chan->isEmpty()) {
                                $payload = $this->packer->pack(
                                    new Packet(0, HasHeartbeatInterface::PING)
                                );
                                $chan->push($payload);
                            }
                        } catch (Throwable $exception) {
                            $this->logger?->error((string) $exception);
                        }
                    }
                } catch (Throwable $exception) {
                    $this->logger?->error((string) $exception);
                } finally {
                    $this->close();
                }
            });
        }
    }

    protected function loop(): void
    {
        $this->heartbeat();

        if ($this->chan !== null && ! $this->chan->isClosing()) {
            return;
        }
        $this->chan = $this->getChannelManager()->make(65535);
        $this->client = $this->makeClient();
        go(function () {
            $reason = '';
            try {
                $chan = $this->chan;
                $client = $this->client;
                while (true) {
                    $data = $client->recvPacket($this->getMaxIdleTime());

                    if ($chan->isClosing()) {
                        $reason = 'channel closed.';
                        break;
                    }

                    if ($data === false || $data === '') {
                        $reason = 'client broken. ' . $client->errMsg;
                        break;
                    }

                    $packet = $this->packer->unpack($data);
                    if ($packet->isHeartbeat()) {
                        continue;
                    }

                    if ($channel = $this->getChannelManager()->get($packet->getId())) {
                        $channel->push(
                            $this->serializer->unserialize($packet->getBody())
                        );
                    } else {
                        $this->logger?->error(sprintf('Recv channel [%d] does not exists.', $packet->getId()));
                    }
                }
            } catch (Throwable $exception) {
                $this->logger?->error((string) $exception);
            } finally {
                $this->logger?->warning('Recv loop broken, wait to restart in next time. The reason is ' . $reason);
                $chan->close();
                $this->getChannelManager()->flush();
                $client->close();
            }
        });

        go(function () {
            $reason = '';
            try {
                $chan = $this->chan;
                $client = $this->client;
                while (true) {
                    $data = $chan->pop();
                    if ($chan->isClosing()) {
                        $reason = 'channel closed.';
                        break;
                    }

                    if (empty($data)) {
                        continue;
                    }

                    $res = $client->sendAll($data, $this->getMaxIdleTime());
                    if ($res === false || strlen($data) !== $res) {
                        throw new SendFailedException('Send data failed. The reason is ' . $client->errMsg);
                    }

                    ++$this->requests;
                }
            } catch (Throwable $exception) {
                $this->logger?->error((string) $exception);
            } finally {
                $this->logger && $this->logger->warning('Send loop broken, wait to restart in next time. The reason is ' . $reason);
                $chan->close();
                $this->getChannelManager()->flush();
                $client->close();
            }
        });
    }

    protected function waitUntilChannelManagerEmpty(): void
    {
        $now = microtime(true);
        $seconds = $this->config['max_wait_close_seconds'];
        while (true) {
            if ($this->channelManager->isEmpty()) {
                return;
            }

            if (microtime(true) - $now >= $seconds) {
                return;
            }

            if (CoordinatorManager::until(Constants::WORKER_EXIT)->yield(0.05)) {
                return;
            }
        }
    }
}
