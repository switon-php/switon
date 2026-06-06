<?php

declare(strict_types=1);

namespace Switon\Rpc\Tests\Unit;

use Switon\Core\Exception\RuntimeException;
use Switon\Rpc\Event\RpcCalling;
use Switon\Rpc\Event\RpcDaemonFailed;
use Switon\Rpc\Event\RpcReceived;
use Switon\Rpc\Tests\TestCase;

class RpcEventsTest extends TestCase
{
    public function testRpcCallingJsonSerializeMatchesPublicState(): void
    {
        $event = new RpcCalling('Svc::go', ['a' => 1], 'cid-9', 2.5);

        $this->assertSame([
            'method' => 'Svc::go',
            'params' => ['a' => 1],
            'correlationId' => 'cid-9',
            'timeout' => 2.5,
        ], $event->jsonSerialize());
    }

    public function testRpcReceivedJsonSerializeMatchesPublicState(): void
    {
        $event = new RpcReceived('Alias', [10], 'c1', '.reply-q');

        $this->assertSame([
            'method' => 'Alias',
            'params' => [10],
            'correlationId' => 'c1',
            'replyTo' => '.reply-q',
        ], $event->jsonSerialize());
    }

    public function testRpcDaemonFailedJsonSerializeExposesErrorDiagnostics(): void
    {
        $error = RuntimeException::of('daemon pull failed', [], 3);
        $event = new RpcDaemonFailed('InFlight::Method', $error);
        $data = $event->jsonSerialize();

        $this->assertSame('InFlight::Method', $data['method']);
        $this->assertSame(RuntimeException::class, $data['error']['class']);
        $this->assertSame('daemon pull failed', $data['error']['message']);
        $this->assertSame(3, $data['error']['code']);
        $this->assertIsString($data['error']['file']);
        $this->assertIsInt($data['error']['line']);
    }
}
