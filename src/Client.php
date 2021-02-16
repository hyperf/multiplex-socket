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

use Hyperf\Engine\Channel;
use Hyperf\Utils\Collection;
use Hyperf\Utils\Coroutine;
use Multiplex\ChannelManager;
use Multiplex\Constract\ClientInterface;
use Multiplex\Constract\HasSerializerInterface;
use Multiplex\Constract\IdGeneratorInterface;
use Multiplex\Constract\PackerInterface;
use Multiplex\Constract\SerializerInterface;
use Multiplex\Exception\ChannelClosedException;
use Multiplex\Exception\ChannelLosedException;
use Multiplex\Exception\ClientConnectFailedException;
use Multiplex\Exception\RecvTimeoutException;
use Multiplex\IdGenerator;
use Multiplex\Packer;
use Multiplex\Packet;
use Multiplex\Serializer\StringSerializer;
use Swoole\Coroutine\Client as SwooleClient;

class Client implements ClientInterface, HasSerializerInterface
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var int
     */
    protected $port;

    /**
     * @var Packer
     */
    protected $packer;

    /**
     * @var SerializerInterface
     */
    protected $serializer;

    /**
     * @var IdGeneratorInterface
     */
    protected $generator;

    /**
     * @var ?Channel
     */
    protected $chan;

    /**
     * @var SwooleClient
     */
    protected $client;

    /**
     * @var Collection
     */
    protected $config;

    /**
     * @var ChannelManager
     */
    protected $channelManager;

    public function __construct(string $name, int $port, ?IdGeneratorInterface $generator = null, ?SerializerInterface $serializer = null, ?PackerInterface $packer = null)
    {
        $this->name = $name;
        $this->port = $port;
        $this->packer = $packer ?? new Packer();
        $this->generator = $generator ?? new IdGenerator();
        $this->serializer = $serializer ?? new StringSerializer();
        $this->channelManager = new ChannelManager();
        $this->config = new Collection([
            'package_max_length' => 1024 * 1024 * 2,
            'recv_timeout' => 10,
            'connect_timeout' => 0.5,
        ]);
    }

    /**
     * @return $this
     */
    public function set(array $settings)
    {
        $this->config = new Collection($settings);
    }

    public function request($data)
    {
        return $this->recv($this->send($data));
    }

    public function send($data): int
    {
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
        } catch (\Throwable $exception) {
            is_int($id) && $this->getChannelManager()->close($id);
            throw $exception;
        }

        return $id;
    }

    public function recv(int $id)
    {
        $this->loop();

        $manager = $this->getChannelManager();
        $chan = $manager->get($id);
        if ($chan === null) {
            throw new ChannelLosedException();
        }

        try {
            $data = $chan->pop($this->config->get('recv_timeout', 10));
            if ($chan->isTimeout()) {
                throw new RecvTimeoutException();
            }

            if ($chan->isClosing()) {
                throw new ChannelClosedException();
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
        $this->client && $this->client->close();
        $this->chan && $this->chan->close();
    }

    protected function makeClient(): SwooleClient
    {
        $client = new SwooleClient(SWOOLE_SOCK_TCP);
        $client->set([
            'open_length_check' => true,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 4,
            'package_max_length' => $this->config->get('package_max_length', 1024 * 1024 * 2),
        ]);
        $ret = $client->connect($this->name, $this->port, $this->config->get('connect_timeout', 0.5));
        if ($ret === false) {
            throw new ClientConnectFailedException($client->errMsg, $client->errCode);
        }
        return $client;
    }

    protected function loop(): void
    {
        if ($this->chan !== null && ! $this->chan->isClosing()) {
            return;
        }
        $this->chan = $this->getChannelManager()->make(65535);
        $this->client = $this->makeClient();
        Coroutine::create(function () {
            try {
                $chan = $this->chan;
                $client = $this->client;
                while (true) {
                    $data = $client->recv(-1);
                    if (! $client->isConnected()) {
                        break;
                    }
                    if ($chan->isClosing()) {
                        break;
                    }

                    $packet = $this->packer->unpack($data);
                    if ($channel = $this->getChannelManager()->get($packet->getId())) {
                        $channel->push(
                            $this->serializer->unserialize($packet->getBody())
                        );
                    }
                }
            } finally {
                $chan->close();
                $client->close();
            }
        });

        Coroutine::create(function () {
            try {
                $chan = $this->chan;
                $client = $this->client;
                while (true) {
                    $data = $chan->pop();
                    if ($chan->isClosing()) {
                        break;
                    }
                    if (! $client->isConnected()) {
                        break;
                    }

                    if (empty($data)) {
                        continue;
                    }

                    $client->send($data);
                }
            } finally {
                $chan->close();
                $client->close();
            }
        });
    }
}
