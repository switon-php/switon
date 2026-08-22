<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Switon\Cdp\Exception;
use Switon\Cdp\WebSocket;

use function bin2hex;
use function chr;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function fread;
use function fwrite;
use function is_file;
use function microtime;
use function ord;
use function pack;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function random_bytes;
use function stream_set_blocking;
use function stream_socket_pair;
use function strlen;
use function str_repeat;
use function substr;
use function sys_get_temp_dir;
use function trim;
use function unlink;
use function usleep;

use const PHP_BINARY;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

class WebSocketStreamTest extends TestCase
{
    #[Test]
    public function textRoundTripAndASplitMessage(): void
    {
        [$socket, $peer] = $this->pair();
        fwrite($peer, $this->frame(0x1, 'hello'));
        fwrite($peer, $this->frame(0x1, 'hel', false) . $this->frame(0x0, 'lo'));

        $this->assertSame('hello', $socket->receiveText(0.2));
        $this->assertSame('hello', $socket->tryReceiveText(0.2));

        $socket->sendText('ping');
        $raw = (string) fread($peer, 64);
        $this->assertSame(0x81, ord($raw[0]));
        $this->assertSame('ping', $this->unmask($raw));
    }

    #[Test]
    public function pingIsAnsweredAndBinaryIsDropped(): void
    {
        [$socket, $peer] = $this->pair();
        fwrite($peer, $this->frame(0x9, 'x') . $this->frame(0x2, 'bin') . $this->frame(0x1, 'ok'));

        $this->assertSame('ok', $socket->tryReceiveText(0.2));
        $pong = (string) fread($peer, 32);
        $this->assertSame(0x8A, ord($pong[0]));
        $this->assertSame('x', $this->unmask($pong));
    }

    #[Test]
    public function aLongFrameAndAMaskedFrameDecode(): void
    {
        [$socket, $peer] = $this->pair();
        $body = str_repeat('a', 200);
        fwrite($peer, $this->frame(0x1, $body));
        fwrite($peer, $this->frame(0x1, 'masked', true, 'mask'));

        $this->assertSame($body, $socket->tryReceiveText(0.2));
        $this->assertSame('masked', $socket->tryReceiveText(0.2));
    }

    #[Test]
    public function aPartialFrameWaitsForTheRest(): void
    {
        [$socket, $peer] = $this->pair();
        $frame = $this->frame(0x1, 'rest');
        fwrite($peer, substr($frame, 0, 1));

        $this->assertNull($socket->tryReceiveText(0.05));

        fwrite($peer, substr($frame, 1));
        $this->assertSame('rest', $socket->tryReceiveText(0.2));
    }

    #[Test]
    public function receiveTimesOutWhenNothingArrives(): void
    {
        [$socket] = $this->pair();

        $this->expectException(Exception::class);
        $socket->receiveText(0.05);
    }

    #[Test]
    public function aPeerCloseRaises(): void
    {
        [$socket, $peer] = $this->pair();
        fwrite($peer, $this->frame(0xA, 'pong') . $this->frame(0x8, ''));

        $this->expectException(Exception::class);
        $socket->tryReceiveText(0.2);
    }

    #[Test]
    public function handshakeAcceptsASwitchingProtocolsReply(): void
    {
        $address = sys_get_temp_dir() . '/switon-cdp-ws-' . bin2hex(random_bytes(4));
        $script = $address . '.php';
        file_put_contents($script, <<<'PHP'
<?php
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
file_put_contents($argv[1], (string) stream_socket_get_name($server, false));
$client = stream_socket_accept($server, 3);
$raw = '';
while (!str_contains($raw, "\r\n\r\n")) {
    $chunk = fread($client, 4096);
    if ($chunk === false || $chunk === '') {
        break;
    }
    $raw .= $chunk;
}
fwrite($client, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n\r\n");
sleep(2);
fclose($client);
PHP);
        $proc = proc_open([PHP_BINARY, $script, $address], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        try {
            $deadline = microtime(true) + 2;
            while (microtime(true) < $deadline && !is_file($address)) {
                usleep(1000);
            }
            $endpoint = trim((string) file_get_contents($address));
            $socket = new WebSocket('ws://' . $endpoint . '/devtools/page', 2.0);
            $socket->close();
            $this->assertTrue(true);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            @unlink($script);
            @unlink($address);
        }
    }

    #[Test]
    public function closeSendsACloseFrame(): void
    {
        [$socket, $peer] = $this->pair();
        $socket->close();
        $raw = (string) fread($peer, 16);
        $this->assertSame(0x88, ord($raw[0]));
        $socket->close();
    }

    /**
     * @return array{0: WebSocket, 1: resource}
     */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);

        return [new PairedSocket($pair[0]), $pair[1]];
    }

    private function frame(int $opcode, string $payload, bool $fin = true, string $mask = ''): string
    {
        $len = strlen($payload);
        $head = chr(($fin ? 0x80 : 0) | $opcode);
        $maskBit = $mask !== '' ? 0x80 : 0;
        if ($len < 126) {
            $head .= chr($maskBit | $len);
        } else {
            $head .= chr($maskBit | 126) . pack('n', $len);
        }
        if ($mask === '') {
            return $head . $payload;
        }

        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        return $head . $mask . $masked;
    }

    private function unmask(string $frame): string
    {
        $len = ord($frame[1]) & 0x7f;
        $mask = substr($frame, 2, 4);
        $payload = substr($frame, 6, $len);
        $text = '';
        for ($i = 0; $i < $len; $i++) {
            $text .= $payload[$i] ^ $mask[$i % 4];
        }

        return $text;
    }
}

final class PairedSocket extends WebSocket
{
    /** @param resource $socket */
    public function __construct($socket)
    {
        $this->socket = $socket;
    }
}
