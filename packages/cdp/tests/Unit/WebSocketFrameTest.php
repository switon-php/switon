<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Switon\Cdp\Exception;
use Switon\Cdp\WebSocket;

class WebSocketFrameTest extends TestCase
{
    #[Test]
    public function encodeFrameMasksTextPayload(): void
    {
        $ref = new ReflectionClass(WebSocket::class);
        $socket = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('encodeFrame');
        $method->setAccessible(true);

        $payload = 'hello-cdp';
        /** @var string $frame */
        $frame = $method->invoke($socket, $payload);

        $this->assertGreaterThanOrEqual(2 + 4 + strlen($payload), strlen($frame));
        $this->assertSame(0x81, ord($frame[0])); // FIN + text
        $this->assertSame(0x80 | strlen($payload), ord($frame[1])); // masked + short length

        $mask = substr($frame, 2, 4);
        $masked = substr($frame, 6);
        $decoded = '';
        for ($i = 0; $i < strlen($payload); $i++) {
            $decoded .= $masked[$i] ^ $mask[$i % 4];
        }
        $this->assertSame($payload, $decoded);
    }

    #[Test]
    public function encodeFrameUsesExtendedLengthForLargePayload(): void
    {
        $ref = new ReflectionClass(WebSocket::class);
        $socket = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('encodeFrame');
        $method->setAccessible(true);

        $payload = str_repeat('a', 200);
        /** @var string $frame */
        $frame = $method->invoke($socket, $payload);

        $this->assertSame(0x81, ord($frame[0]));
        $this->assertSame(0x80 | 126, ord($frame[1]));
        $len = unpack('n', substr($frame, 2, 2))[1];
        $this->assertSame(200, $len);
    }

    #[Test]
    public function connectRejectsAUrlWithoutAHost(): void
    {
        $this->expectException(Exception::class);
        new WebSocket('not-a-websocket');
    }
}
