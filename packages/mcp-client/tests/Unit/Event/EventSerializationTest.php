<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Switon\Core\Json;
use Switon\McpClient\Event\ToolCalled;
use Switon\McpClient\Event\ToolCalling;
use Switon\McpClient\Event\TransportRead;
use Switon\McpClient\Event\TransportWrite;

#[CoversClass(TransportRead::class)]
#[CoversClass(TransportWrite::class)]
#[CoversClass(ToolCalling::class)]
#[CoversClass(ToolCalled::class)]
class EventSerializationTest extends TestCase
{
    public function testTransportReadJsonSerializeOmitsBody(): void
    {
        $message = ['method' => 'ping', 'id' => 1, 'result' => ['x' => str_repeat('y', 40)]];
        $event = new TransportRead($message);

        $this->assertSame([
            'method' => 'ping',
            'id' => 1,
            'message_bytes' => strlen(Json::stringify($message)),
        ], $event->jsonSerialize());
        $this->assertArrayNotHasKey('result', $event->jsonSerialize());
    }

    public function testTransportWriteJsonSerializeOmitsBody(): void
    {
        $message = ['method' => 'ping', 'id' => 1, 'params' => ['q' => str_repeat('z', 40)]];

        $this->assertSame([
            'method' => 'ping',
            'id' => 1,
            'message_bytes' => strlen(Json::stringify($message)),
        ], (new TransportWrite($message))->jsonSerialize());
    }

    public function testToolLifecycleEventsJsonSerializeOmitsBodies(): void
    {
        $arguments = ['key' => 'value', 'blob' => str_repeat('a', 60)];
        $result = ['content' => [['type' => 'text', 'text' => str_repeat('c', 60)]]];

        $calling = new ToolCalling('ping', $arguments);
        $called = new ToolCalled('ping', $arguments, $result);

        $this->assertSame(['name' => 'ping', 'argument_keys' => ['key', 'blob']], $calling->jsonSerialize());
        $this->assertSame([
            'name' => 'ping',
            'argument_keys' => ['key', 'blob'],
            'result_bytes' => strlen(Json::stringify($result)),
        ], $called->jsonSerialize());
        $this->assertArrayNotHasKey('arguments', $called->jsonSerialize());
        $this->assertArrayNotHasKey('result', $called->jsonSerialize());
    }
}
