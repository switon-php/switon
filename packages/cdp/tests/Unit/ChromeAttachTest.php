<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Switon\Cdp\Exception;
use Switon\Cdp\HeadlessChrome;
use Switon\Cdp\Launcher;
use Switon\Cdp\Session;

use function fclose;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function proc_close;
use function proc_open;
use function str_contains;
use function stream_socket_get_name;
use function sys_get_temp_dir;
use function trim;
use function unlink;
use function usleep;

use const PHP_BINARY;

class ChromeAttachTest extends TestCase
{
    #[Test]
    public function aClosedPortWithAutoLaunchOffIsReported(): void
    {
        $chrome = new ClosedPortChrome();

        $this->expectException(Exception::class);
        $chrome->open('https://example.com/');
    }

    #[Test]
    public function aFailedNavigationDropsTheTabItOpened(): void
    {
        $chrome = new DiscardingChrome();

        try {
            $chrome->open('https://example.com/');
            $this->fail('A blocked navigation must not become a page.');
        } catch (Exception $e) {
            $this->assertStringContainsString('failed', $e->getMessage());
        }

        $this->assertTrue($chrome->dropped);
        $this->assertTrue($chrome->socket->closed);
        $chrome->runSession('ws://127.0.0.1:1/devtools/browser');
    }

    #[Test]
    public function tabsAreAddressedOverTheDevToolsHttpEndpoints(): void
    {
        [$proc, $endpoint, $addressFile, $script] = $this->server();
        $port = (int) substr($endpoint, (int) strrpos($endpoint, ':') + 1);
        $chrome = new ListeningChrome($port);
        try {
            $this->assertSame(1.0, $chrome->timeout());
            $chrome->dropTarget('  ');
            $chrome->dropTarget('a b');
            $chrome->focusTarget('');
            $chrome->focusTarget('tab');
            $this->assertStringContainsString('headless', $chrome->profile(''));
            $this->assertSame('/tmp/switon-profile', $chrome->profile('/tmp/switon-profile'));
            $chrome->close();
            $chrome->runSession('');

            try {
                $chrome->open('https://example.com/');
                $this->fail('A rejected handshake must not become a page.');
            } catch (Exception $e) {
                $this->assertStringContainsString('WebSocket', $e->getMessage());
            }
        } finally {
            proc_close($proc);
            @unlink($script);
            @unlink($addressFile);
        }
    }

    /**
     * @return array{0: resource, 1: string, 2: string, 3: string}
     */
    private function server(): array
    {
        $addressFile = sys_get_temp_dir() . '/switon-cdp-attach-' . bin2hex(random_bytes(4));
        $script = $addressFile . '.php';
        file_put_contents($script, <<<'PHP'
<?php
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
file_put_contents($argv[1], (string) stream_socket_get_name($server, false));
for ($i = 0; $i < 8; $i++) {
    $client = stream_socket_accept($server, 5);
    if ($client === false) {
        break;
    }
    $raw = (string) fread($client, 8192);
    $line = (string) strtok($raw, "\r\n");
    if (str_contains($line, '/devtools')) {
        fwrite($client, "HTTP/1.1 400 No\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        fclose($client);
        continue;
    }
    if (str_contains($line, '/json/list')) {
        $body = '[{"type":"browser"},{"type":"page","id":""},{"type":"page","id":"old"}]';
    } elseif (str_contains($line, 'PUT ') && str_contains($line, '/json/new')) {
        $body = '{}';
    } elseif (str_contains($line, '/json/new')) {
        $body = json_encode([
            'id' => 'new',
            'webSocketDebuggerUrl' => 'ws://' . stream_socket_get_name($server, false) . '/devtools/page/new',
        ]);
    } else {
        $body = '{"ok":true}';
    }
    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
    fclose($client);
}
PHP);
        $proc = proc_open([PHP_BINARY, $script, $addressFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $deadline = microtime(true) + 2;
        while (microtime(true) < $deadline && !is_file($addressFile)) {
            usleep(1000);
        }

        return [$proc, trim((string) file_get_contents($addressFile)), $addressFile, $script];
    }
}

final class ClosedPortChrome extends HeadlessChrome
{
    public function __construct()
    {
        $this->port = 1;
        $this->autoLaunch = false;
        $this->timeout = 1.0;
        $this->launcher = new class () extends Launcher {
            public function __construct()
            {
            }
        };
    }
}

final class ListeningChrome extends HeadlessChrome
{
    public function __construct(int $port)
    {
        $this->port = $port;
        $this->timeout = 1.0;
        $this->autoLaunch = false;
        $this->launcher = new class () extends Launcher {
            public function __construct()
            {
            }
        };
    }

    public function profile(string $dir): string
    {
        $this->userDataDir = $dir;

        return $this->profileDir();
    }

    public function runSession(string $url): void
    {
        $this->withDebuggerSession($url, 0.2, static function (): void {
        });
    }

    protected function ensure(): void
    {
    }
}

final class DiscardingChrome extends HeadlessChrome
{
    public bool $dropped = false;

    public ScriptedSocket $socket;

    public function __construct()
    {
        $this->port = 1;
        $this->timeout = 1.0;
        $this->autoLaunch = false;
        $this->launcher = new class () extends Launcher {
            public function __construct()
            {
            }
        };
        $this->socket = new ScriptedSocket();
        $this->socket->results['Page.navigate'] = ['errorText' => 'blocked'];
    }

    public function dropTarget(string $targetId): void
    {
        $this->dropped = $targetId === 'tab';
    }

    public function runSession(string $url): void
    {
        $this->withDebuggerSession($url, 0.2, static function (): void {
            throw new \RuntimeException('ignored');
        });
    }

    protected function ensure(): void
    {
    }

    protected function connectNewTab(): array
    {
        return [
            'session' => new Session($this->socket),
            'id' => 'tab',
        ];
    }
}
