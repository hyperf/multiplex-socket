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

use Multiplex\Socket\Client;

/**
 * @internal
 * @coversNothing
 */
class ClientTest extends AbstractTestCase
{
    public function testRequest()
    {
        $this->runInCoroutine(function () {
            $client = new Client('127.0.0.1', 9601);
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
}