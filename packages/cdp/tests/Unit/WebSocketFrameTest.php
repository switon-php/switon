<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Switon\Cdp\Exception;
use Switon\Cdp\WebSocket;

class WebSocketFrameTest extends TestCase
{
    #[Test]
    public function encodeFrameMasksTextPayload(): void
    {
        $socket = new FrameSocket();
        $payload = 'hello-cdp';
        $frame = $socket->frame($payload);

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
        $payload = str_repeat('a', 200);
        $frame = (new FrameSocket())->frame($payload);

        $this->assertSame(0x81, ord($frame[0]));
        $this->assertSame(0x80 | 126, ord($frame[1]));
        $len = unpack('n', substr($frame, 2, 2))[1];
        $this->assertSame(200, $len);
    }

    #[Test]
    public function bufferedFrameWaitsUntilTheExtendedHeaderAndPayloadArrive(): void
    {
        $socket = new FrameSocket();

        $this->assertNull($socket->read("\x81\x7e\x00"));
        $this->assertNull($socket->read("\x81\x7e\x00\x05"));
        $this->assertNull($socket->read("\x81\x7f\x00\x00"));
        $this->assertNull($socket->read("\x81\x7f" . pack('J', 1)));
    }

    #[Test]
    public function connectRejectsAUrlWithoutAHost(): void
    {
        $this->expectException(Exception::class);
        new WebSocket('not-a-websocket');
    }
}

class FrameSocket extends WebSocket
{
    public function __construct()
    {
    }

    public function frame(string $payload): string
    {
        return $this->encodeFrame($payload);
    }

    public function read(string $buffer): ?array
    {
        $this->buffer = $buffer;

        return $this->bufferedFrame();
    }
}
