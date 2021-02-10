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
use Multiplex\ChannelMapper;
use Multiplex\Constract\ClientInterface;
use Multiplex\Constract\HasSerializerInterface;
use Multiplex\Constract\IdGeneratorInterface;
use Multiplex\Constract\PackerInterface;
use Multiplex\Constract\SerializerInterface;
use Multiplex\IdGenerator;
use Multiplex\Packer;
use Multiplex\Serializer\StringSerializer;

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

    protected $waiter;

    public function __construct(string $name, int $port, ?IdGeneratorInterface $generator, ?SerializerInterface $serializer = null, ?PackerInterface $packer = null)
    {
        $this->name = $name;
        $this->port = $port;
        $this->packer = $packer ?? new Packer();
        $this->generator = $generator ?? new IdGenerator();
        $this->serializer = $serializer ?? new StringSerializer();
        $this->waiter = new Channel();
    }

    public function send($data): int
    {
    }

    public function recv(int $id)
    {
        // TODO: Implement recv() method.
    }

    public function getSerializer(): SerializerInterface
    {
        return $this->serializer;
    }

    public function getChannelMapper(): ChannelMapper
    {
        // TODO: Implement getChannelMapper() method.
    }

    public function getWaiter(): Channel
    {
        // TODO: Implement getWaiter() method.
    }
}
