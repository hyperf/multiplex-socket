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
use Multiplex\Packet;
use Multiplex\Socket\Server;

require_once __DIR__ . '/../vendor/autoload.php';

run(function () {
    $server = new Server();
    $config = collect([]);
    $server->bind('0.0.0.0', 9601, $config)->handle(static function (Packet $packet) {
        return 'Hello ' . $packet->getBody();
    })->start();
});
