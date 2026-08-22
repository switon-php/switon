<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Switon\Cdp\Exception;
use Switon\Cdp\HeadedChrome;
use Switon\Cdp\Launcher;

use function file_get_contents;
use function file_put_contents;
use function is_file;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function sys_get_temp_dir;
use function trim;
use function unlink;
use function usleep;

use const PHP_BINARY;

class HeadedChromeWindowTest extends TestCase
{
    #[Test]
    public function aHeadlessListenerIsRefusedAndAClosedPortNamesTheHeadedStartScript(): void
    {
        $headless = new VisibleChrome(1, new QuietLauncher(open: true, headless: true));

        try {
            $headless->open('https://example.com/');
            $this->fail('A headed service must not attach to a headless listener.');
        } catch (Exception $e) {
            $this->assertStringContainsString('headless', $e->getMessage());
        }

        $closed = new VisibleChrome(1, new QuietLauncher(open: false, headless: null));

        try {
            $closed->open('https://example.com/');
            $this->fail('A closed port with autoLaunch off must name the start script.');
        } catch (Exception $e) {
            $this->assertStringContainsString('start-cdp', $e->getMessage());
            $this->assertStringNotContainsString('www/switon', $e->getMessage());
            $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';
            if ($home !== '') {
                $this->assertStringNotContainsString($home, $e->getMessage());
            }
        }
    }

    #[Test]
    public function focusRestoresAMinimizedWindowAndBringsTheTabForward(): void
    {
        [$proc, $port, $address, $script] = $this->server();
        $chrome = new VisibleChrome($port, new QuietLauncher(open: false, headless: null));
        try {
            $chrome->focusTarget('tab');
            $this->assertTrue($chrome->quiet->raised);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            @unlink($script);
            @unlink($address);
        }
    }

    /**
     * @return array{0: resource, 1: int, 2: string, 3: string}
     */
    private function server(): array
    {
        $address = sys_get_temp_dir() . '/switon-cdp-headed-' . bin2hex(random_bytes(4));
        $script = $address . '.php';
        file_put_contents($script, <<<'PHP'
<?php
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
file_put_contents($argv[1], (string) stream_socket_get_name($server, false));
$guid = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
for ($n = 0; $n < 8; $n++) {
    $client = @stream_socket_accept($server, 3);
    if ($client === false) {
        break;
    }
    $raw = '';
    while (!str_contains($raw, "\r\n\r\n")) {
        $chunk = fread($client, 4096);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $raw .= $chunk;
    }
    $split = strpos($raw, "\r\n\r\n");
    $head = $split === false ? $raw : substr($raw, 0, $split);
    $buf = $split === false ? '' : substr($raw, $split + 4);
    if (str_contains($head, 'Upgrade: websocket')) {
        preg_match('/Sec-WebSocket-Key:\s*(.+)\r/', $head, $match);
        $accept = base64_encode(sha1(trim($match[1]) . $guid, true));
        fwrite($client, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");
        stream_set_blocking($client, false);
        $deadline = microtime(true) + 2;
        while (microtime(true) < $deadline) {
            $chunk = fread($client, 8192);
            if (is_string($chunk) && $chunk !== '') {
                $buf .= $chunk;
            }
            $payload = next_text($buf);
            if ($payload === null) {
                if ($chunk === '') {
                    usleep(1000);
                }
                if (feof($client)) {
                    break;
                }
                continue;
            }
            $message = json_decode($payload, true);
            $method = (string) ($message['method'] ?? '');
            $result = $method === 'Browser.getWindowForTarget'
                ? ['windowId' => 4, 'bounds' => ['windowState' => 'minimized']]
                : new stdClass();
            $reply = json_encode(['id' => $message['id'] ?? 0, 'result' => $result]);
            $len = strlen($reply);
            $frame = chr(0x81) . ($len < 126 ? chr($len) : chr(126) . pack('n', $len)) . $reply;
            fwrite($client, $frame);
        }
        fclose($client);
        continue;
    }
    $name = (string) stream_socket_get_name($server, false);
    if (str_contains($head, '/json/version')) {
        $body = json_encode(['webSocketDebuggerUrl' => 'ws://' . $name . '/devtools/browser']);
    } elseif (str_contains($head, '/json/list')) {
        $body = json_encode([['id' => 'tab', 'webSocketDebuggerUrl' => 'ws://' . $name . '/devtools/page']]);
    } else {
        $body = '{"ok":true}';
    }
    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
    fclose($client);
}

/** @return string|null Text payload, or null when the frame is incomplete or not text. */
function next_text(string &$buf): ?string
{
    if (strlen($buf) < 2) {
        return null;
    }
    $opcode = ord($buf[0]) & 0x0f;
    $b2 = ord($buf[1]);
    $len = $b2 & 0x7f;
    $offset = 2;
    if ($len === 126) {
        if (strlen($buf) < 4) {
            return null;
        }
        $len = unpack('n', substr($buf, 2, 2))[1];
        $offset = 4;
    }
    $masked = ($b2 & 0x80) !== 0;
    $maskLen = $masked ? 4 : 0;
    if (strlen($buf) < $offset + $maskLen + $len) {
        return null;
    }
    $mask = $masked ? substr($buf, $offset, 4) : '';
    $payload = substr($buf, $offset + $maskLen, $len);
    $buf = substr($buf, $offset + $maskLen + $len);
    if ($opcode !== 1) {
        return null;
    }
    if ($mask !== '') {
        $text = '';
        for ($i = 0; $i < $len; $i++) {
            $text .= $payload[$i] ^ $mask[$i % 4];
        }
        $payload = $text;
    }

    return $payload;
}
PHP);

        $proc = proc_open([PHP_BINARY, $script, $address], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $deadline = microtime(true) + 2;
        while (microtime(true) < $deadline && !is_file($address)) {
            usleep(1000);
        }
        $endpoint = trim((string) file_get_contents($address));
        $port = (int) substr($endpoint, (int) strrpos($endpoint, ':') + 1);

        return [$proc, $port, $address, $script];
    }
}

final class QuietLauncher extends Launcher
{
    public bool $raised = false;

    public function __construct(
        private readonly bool $open,
        private readonly ?bool $headless,
    ) {
    }

    public function portOpen(int $port): bool
    {
        return $this->open;
    }

    public function listenerIsHeadless(int $port): ?bool
    {
        return $this->headless;
    }

    public function foreground(int $port): void
    {
        $this->raised = true;
    }
}

final class VisibleChrome extends HeadedChrome
{
    public QuietLauncher $quiet;

    public function __construct(int $port, QuietLauncher $launcher)
    {
        $this->port = $port;
        $this->timeout = 2.0;
        $this->autoLaunch = false;
        $this->launcher = $launcher;
        $this->quiet = $launcher;
    }
}
