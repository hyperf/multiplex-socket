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
    $server->bind('0.0.0.0', 9601, [])->handle(static function (Packet $packet) {
        if ($packet->getBody() === 'timeout') {
            sleep(5);
            return 'Hello ' . $packet->getBody();
        }
        return 'Hello ' . $packet->getBody();
    })->start();
});
