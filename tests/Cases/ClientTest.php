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

namespace HyperfTest\Cases;

use Hyperf\Coordinator\Constants;
use Hyperf\Coordinator\CoordinatorManager;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Engine\Channel;
use Hyperf\Support\Reflection\ClassInvoker;
use Mockery;
use Multiplex\Contract\HasHeartbeatInterface;
use Multiplex\Contract\PackerInterface;
use Multiplex\Exception\ChannelClosedException;
use Multiplex\Exception\ClientConnectFailedException;
use Multiplex\Exception\RecvTimeoutException;
use Multiplex\Packer;
use Multiplex\Packet;
use Multiplex\Socket\Client;
use Throwable;

use function Hyperf\Coroutine\parallel;
use function Hyperf\Tappable\tap;

/**
 * @internal
 * @coversNothing
 */
class ClientTest extends AbstractTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testRequest()
    {
        $this->runInCoroutine(function () {
            $client = new Client('127.0.0.1', 9601);
            $client->set([
                'heartbeat' => null,
            ]);

            $asserts = [
                'World',
                'Hyperf',
                'Swoole',
            ];
            $callbacks = [];
            foreach ($asserts as $assert) {
                $callbacks[] = function () use ($client, $assert) {
                    $ret = $client->request($assert);

                    $this->assertSame('Hello ' . $assert, $ret);
                };
            }

            parallel($callbacks);
            $client->close();
        });
    }

    public function testRequestWhenChannelClosed()
    {
        $this->runInCoroutine(function () {
            $client = new Client('127.0.0.1', 9601);
            $client->set([
                'heartbeat' => null,
            ]);
            Coroutine::create(function () use ($client) {
                sleep(1);
                $client->close();
            });
            try {
                $client->request('timeout');
                $this->assertTrue(false);
            } catch (Throwable $exception) {
                $this->assertInstanceOf(ChannelClosedException::class, $exception);
            } finally {
                $client->close();
            }
        });
    }

    public function testRequestWhenRecvTimeout()
    {
        $this->runInCoroutine(function () {
            $client = new Client('127.0.0.1', 9601);
            $client->set([
                'heartbeat' => null,
                'recv_timeout' => 1,
            ]);
            try {
                $client->request('timeout');
                $this->assertTrue(false);
            } catch (Throwable $exception) {
                $this->assertStringContainsString('pop timeout after 1 seconds', $exception->getMessage());
                $this->assertInstanceOf(RecvTimeoutException::class, $exception);
            } finally {
                $client->close();
            }
        });
    }

    public function testConnectFailed()
    {
        $this->runInCoroutine(function () {
            $client = new Client('127.0.0.1', 9602);
            $client->set([
                'heartbeat' => null,
            ]);
            try {
                $ret = $client->request('Hello World.');
            } catch (ClientConnectFailedException $exception) {
                $this->assertSame('Connection refused', $exception->getMessage());
            }

            $this->assertTrue((new ClassInvoker($client))->chan->isClosing());
        });
    }

    public function testGetMaxIdleTime()
    {
        $client = new Client('127.0.0.1', 9602);
        $client->set([
            'heartbeat' => 12,
        ]);

        tap(new ClassInvoker($client), function ($client) {
            $this->assertSame(12, $client->getHeartbeat());
            $this->assertSame(24, $client->getMaxIdleTime());
        });

        $client = new Client('127.0.0.1', 9602);
        $client->set([
            'heartbeat' => null,
        ]);

        tap(new ClassInvoker($client), function ($client) {
            $this->assertSame(null, $client->getHeartbeat());
            $this->assertSame(-1, $client->getMaxIdleTime());
        });

        $this->runInCoroutine(function () {
            $client = new class('127.0.0.1', 9601) extends Client {
                protected function getMaxIdleTime(): int
                {
                    return 1;
                }
            };

            $ret = $client->request('World.');
            $this->assertSame('Hello World.', $ret);

            CoordinatorManager::until(Constants::WORKER_EXIT)->resume();
            CoordinatorManager::clear(Constants::WORKER_EXIT);
        });
    }

    public function testSendHeartbeat()
    {
        $this->runInCoroutine(function () {
            $packer = $packer = Mockery::mock(PackerInterface::class);
            $packer->shouldReceive('pack')->withAnyArgs()->andReturnUsing(function () {
                $packer = new Packer();
                return $packer->pack(new Packet(0, Packet::PING));
            });
            $chan = new Channel(1);
            $packer->shouldReceive('unpack')->withAnyArgs()->once()->andReturnUsing(function ($string) use ($chan) {
                $packer = new Packer();
                $packet = $packer->unpack($string);
                $chan->push($packet);
                return $packet;
            });
            $client = new Client('127.0.0.1', 9601, null, null, $packer);
            $client->set([
                'heartbeat' => null,
            ]);
            $client->send('xxx');
            $packet = $chan->pop(-1);
            $this->assertInstanceOf(HasHeartbeatInterface::class, $packet);
            $this->assertTrue($packet->isHeartbeat());
            $client->close();
        });
    }

    public function testHeartbeatNullInLoop()
    {
        $this->runInCoroutine(function () {
            $packer = $packer = Mockery::mock(PackerInterface::class);
            $packer->shouldReceive('pack')->withAnyArgs()->andReturnUsing(function ($packet) {
                $packer = new Packer();
                return $packer->pack($packet);
            });
            $chan = new Channel(1);
            $packer->shouldReceive('unpack')->withAnyArgs()->andReturnUsing(function ($string) use ($chan) {
                $packer = new Packer();
                $packet = $packer->unpack($string);
                $chan->push($packet);
                return $packet;
            });
            $client = new Client('127.0.0.1', 9601, null, null, $packer);
            $client->set([
                'package_max_length' => 1024 * 1024 * 2,
                'recv_timeout' => 10,
                'connect_timeout' => 0.5,
                'heartbeat' => null,
            ]);

            $client->send('xxx');
            $packet = $chan->pop(-1);
            $client->close();
            $this->assertSame('Hello xxx', $packet->getBody());
        });
    }

    public function testHeartbeatInLoop()
    {
        $this->runInCoroutine(function () {
            $packer = $packer = Mockery::mock(PackerInterface::class);
            $packer->shouldReceive('pack')->withAnyArgs()->andReturnUsing(function ($packet) {
                $packer = new Packer();
                return $packer->pack($packet);
            });
            $chan = new Channel(1);
            $packer->shouldReceive('unpack')->withAnyArgs()->andReturnUsing(function ($string) use ($chan) {
                $packer = new Packer();
                $packet = $packer->unpack($string);
                $chan->push($packet);
                return $packet;
            });
            $client = new Client('127.0.0.1', 9601, null, null, $packer);
            $client->set([
                'package_max_length' => 1024 * 1024 * 2,
                'recv_timeout' => 10,
                'connect_timeout' => 0.5,
                'heartbeat' => 1,
            ]);

            (new ClassInvoker($client))->loop();
            $packet = $chan->pop(-1);
            $this->assertTrue($packet->isHeartbeat());
            CoordinatorManager::until(Constants::WORKER_EXIT)->resume();
            CoordinatorManager::clear(Constants::WORKER_EXIT);
        });
    }

    public function testPushToClosedChannel()
    {
        $this->runInCoroutine(function () {
            $chan = new Channel(1);
            $chan->close();

            $this->assertFalse($chan->push(1));
        });
    }
}
