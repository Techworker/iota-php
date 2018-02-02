<?php

declare(strict_types=1);

/*
 * This file is part of the IOTA PHP package.
 *
 * (c) Benjamin Ansbach <benjaminansbach@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace IOTA\Tests;

use PHPUnit\Framework\TestCase;
use IOTA\ClientApi\ClientApi;
use IOTA\Client;
use IOTA\Node;
use IOTA\RemoteApi\RemoteApi;

/**
 * Class IOTATest.
 *
 * @coversNothing
 */
class IOTATest extends TestCase
{
    public function testGetNodes()
    {
        $nodes = [
            new Node('http://127.0.0.1:14265'),
            new Node('http://127.0.0.2:14265'),
            'mynode' => new Node('http://myNode'),
        ];

        $iota = new Client(new Container(), $nodes);

        static::assertCount(3, $iota->getNodes());

        // without key
        $node = $iota->getNode();
        static::assertInstanceOf(Node::class, $node);
        static::assertEquals($node, $iota->getLastUsedNode());

        $node = $iota->getNode('mynode');
        static::assertEquals($node->getHost(), 'http://myNode');
    }

    public function testInvalidNode()
    {
        $this->expectException(\Exception::class);

        $nodes = [];
        $iota = new Client(new Container(), $nodes);
        $iota->getNode('-1');
    }

    public function testGetRemoteApi()
    {
        $iota = new Client(new Container(), []);
        static::assertInstanceOf(RemoteApi::class, $iota->getRemoteApi());
    }

    public function testGetClientApi()
    {
        $iota = new Client(new Container(), []);
        static::assertInstanceOf(ClientApi::class, $iota->getClientApi());
    }
}
