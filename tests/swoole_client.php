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
require_once __DIR__ . '/../vendor/autoload.php';

$max = 10000;

run(function () use ($max) {
    $time = microtime(true);
    $client = new \Multiplex\Socket\Client('127.0.0.1', 9601);
    $channel = new \Hyperf\Engine\Channel($max);
    for ($i = 0; $i < $max; ++$i) {
        go(function () use ($client, $channel) {
            $channel->push($client->request('World.'));
        });
    }

    for ($i = 0; $i < $max; ++$i) {
        $channel->pop();
    }

    var_dump('多路复用耗时：' . (microtime(true) - $time));
    $client->close();
});

run(function () use ($max) {
    $length = 32;
    $pool = new \Hyperf\Engine\Channel($length);
    while ($length--) {
        $client = new \Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
        $client->set([
            'open_length_check' => true,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 4,
            'package_max_length' => 1024 * 1024 * 2,
        ]);
        $client->connect('127.0.0.1', 9601, 0.5);
        $pool->push($client);
    }

    $time = microtime(true);
    $channel = new \Hyperf\Engine\Channel($max);
    $packer = new \Multiplex\Packer();
    for ($i = 0; $i < $max; ++$i) {
        go(function () use ($pool, $channel, $packer) {
            try {
                /** @var \Swoole\Coroutine\Client $client */
                $client = $pool->pop();
                $client->send($packer->pack(new \Multiplex\Packet(0, 'World.')));
                $ret = $client->recv();
            } catch (\Throwable $exception) {
                var_dump($exception->getMessage());
            } finally {
                $channel->push($ret);
                $pool->push($client);
            }
        });
    }

    for ($i = 0; $i < $max; ++$i) {
        $channel->pop();
    }

    var_dump('连接池耗时：' . (microtime(true) - $time));
});
