<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Switon\Core\Json;
use Switon\Mcp\Event\ToolCalled;
use Switon\Mcp\Event\ToolCalling;
use Switon\Mcp\Event\TransportRead;
use Switon\Mcp\Event\TransportWrite;
use Switon\Mcp\Tests\Fixtures\PingTool;

#[CoversClass(TransportRead::class)]
#[CoversClass(TransportWrite::class)]
#[CoversClass(ToolCalling::class)]
#[CoversClass(ToolCalled::class)]
class EventSerializationTest extends TestCase
{
    public function testTransportReadJsonSerializeOmitsBody(): void
    {
        $request = ['method' => 'ping', 'id' => 1, 'params' => ['huge' => str_repeat('x', 100)]];
        $event = new TransportRead($request);

        $this->assertSame([
            'method' => 'ping',
            'id' => 1,
            'request_bytes' => strlen(Json::stringify($request)),
        ], $event->jsonSerialize());
        $this->assertArrayNotHasKey('params', $event->jsonSerialize());
    }

    public function testTransportWriteJsonSerializeOmitsBodies(): void
    {
        $request = ['method' => 'ping', 'id' => 1];
        $response = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => [str_repeat('t', 50)]]];
        $event = new TransportWrite($request, $response);

        $this->assertSame([
            'method' => 'ping',
            'id' => 1,
            'request_bytes' => strlen(Json::stringify($request)),
            'response_bytes' => strlen(Json::stringify($response)),
        ], $event->jsonSerialize());
        $this->assertArrayNotHasKey('request', $event->jsonSerialize());
        $this->assertArrayNotHasKey('response', $event->jsonSerialize());
    }

    public function testToolLifecycleEventsJsonSerializeOmitsBodies(): void
    {
        $tool = new PingTool();
        $arguments = ['name' => 'ping', 'blob' => str_repeat('b', 80)];
        $result = ['content' => [['type' => 'text', 'text' => str_repeat('r', 80)]]];

        $calling = new ToolCalling('ping', $tool, $arguments);
        $called = new ToolCalled('ping', $tool, $arguments, $result);

        $this->assertSame(['name' => 'ping', 'argument_keys' => ['name', 'blob']], $calling->jsonSerialize());
        $this->assertSame([
            'name' => 'ping',
            'argument_keys' => ['name', 'blob'],
            'result_bytes' => strlen(Json::stringify($result)),
        ], $called->jsonSerialize());
        $this->assertArrayNotHasKey('arguments', $called->jsonSerialize());
        $this->assertArrayNotHasKey('result', $called->jsonSerialize());
    }
}
