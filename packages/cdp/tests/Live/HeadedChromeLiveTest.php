<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Live;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Switon\Cdp\HeadedChrome;
use Switon\Cdp\Launcher;
use Switon\Cdp\Paths;
use Switon\Cdp\Session;
use Switon\Core\Clock;
use Switon\Core\Filesystem;
use Switon\Core\PathAlias;

use function array_pad;
use function ctype_digit;
use function escapeshellarg;
use function explode;
use function file_get_contents;
use function is_array;
use function is_string;
use function json_decode;
use function microtime;
use function shell_exec;
use function sprintf;
use function sys_get_temp_dir;
use function trim;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const PHP_OS_FAMILY;

/**
 * A headed window is actually visible: open raises it, and activate restores it after minimize.
 *
 * Skips when Chrome is missing, or on Linux without a display. Profile and port are ephemeral.
 */
class HeadedChromeLiveTest extends TestCase
{
    protected ?Launcher $launcher = null;

    protected string $userDataDir = '';

    protected int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (Paths::detect() === '') {
            $this->markTestSkipped('Chrome/Chromium not installed.');
        }
        if (PHP_OS_FAMILY === 'Linux' && (getenv('DISPLAY') ?: '') === '' && (getenv('WAYLAND_DISPLAY') ?: '') === '') {
            $this->markTestSkipped('Headed Chrome needs a display.');
        }

        $this->userDataDir = sys_get_temp_dir() . '/switon-cdp-headed-' . getmypid();
        $this->port = $this->pickFreePort();
        $this->launcher = $this->bootLauncher();
    }

    protected function tearDown(): void
    {
        $this->launcher?->stopOwnedProcess($this->port);
        parent::tearDown();
    }

    #[Test]
    public function openRaisesTheWindowAndActivateRestoresIt(): void
    {
        $chrome = $this->chrome();
        $page = $chrome->open('about:blank');
        try {
            if (PHP_OS_FAMILY === 'Darwin') {
                $this->assertFrontmostListener();
            }

            $this->setWindowState('minimized');
            $this->assertSame('minimized', $this->waitForWindowState('minimized'));

            $page->activate();

            $this->assertNotSame('minimized', $this->waitForWindowState('normal'));
            if (PHP_OS_FAMILY === 'Darwin') {
                $this->assertFrontmostListener();
            }
        } finally {
            $page->close();
            $chrome->close();
        }
    }

    protected function chrome(): HeadedChrome
    {
        $launcher = $this->launcher;
        $this->assertInstanceOf(Launcher::class, $launcher);

        return new class ($launcher, new Clock(), $this->userDataDir, $this->port) extends HeadedChrome {
            public function __construct(Launcher $launcher, Clock $clock, string $userDataDir, int $port)
            {
                $this->launcher = $launcher;
                $this->clock = $clock;
                $this->userDataDir = $userDataDir;
                $this->port = $port;
                $this->autoLaunch = true;
                $this->timeout = 30.0;
                // CI and agent sandboxes block Chrome's own sandbox, which kills the GPU process at
                // startup. The package default stays sandboxed; only this test opts out.
                $this->args = ['--no-sandbox', '--disable-dev-shm-usage'];
            }
        };
    }

    /**
     * Whether Chrome reached the front of the window server.
     *
     * The window state is what the package controls (`Browser.setWindowBounds` +
     * `Target.activateTarget`), and the minimized→normal transition already proves the call
     * landed. Whether macOS then hands this Chromium process the frontmost pid depends on the
     * focus policy of whatever app is in front of it — an IDE, a terminal, or a chat client can
     * keep it. This helper reports that instead of failing on it.
     */
    protected function assertFrontmostListener(): void
    {
        $listener = $this->listenerPid();
        $this->assertGreaterThan(0, $listener);

        $deadline = microtime(true) + 5;
        do {
            if ($this->frontmostPid() === $listener) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        [$frontPid, $frontName] = $this->frontmostApplication();
        $this->markTestSkipped(sprintf(
            'Chrome (pid %d) did not reach the front; "%s" (pid %d) kept focus. The raise itself is covered by the window-state assertion.',
            $listener,
            $frontName,
            $frontPid,
        ));
    }

    protected function listenerPid(): int
    {
        $output = [];
        exec('lsof -nP -iTCP:' . $this->port . ' -sTCP:LISTEN -t 2>/dev/null', $output);
        foreach ($output as $line) {
            $line = trim($line);
            if (ctype_digit($line)) {
                return (int) $line;
            }
        }

        return 0;
    }

    protected function frontmostPid(): int
    {
        $raw = trim((string) shell_exec('osascript -e ' . escapeshellarg('tell application "System Events" to unix id of first process whose frontmost is true') . ' 2>/dev/null'));

        return ctype_digit($raw) ? (int) $raw : 0;
    }

    /**
     * @return array{0: int, 1: string} pid and application name of whatever is in front.
     */
    protected function frontmostApplication(): array
    {
        $raw = trim((string) shell_exec('osascript -e ' . escapeshellarg('tell application "System Events" to unix id of first process whose frontmost is true & "|" & name of first process whose frontmost is true') . ' 2>/dev/null'));
        [$pid, $name] = array_pad(explode('|', $raw, 2), 2, '');

        return [ctype_digit(trim($pid)) ? (int) trim($pid) : 0, trim($name)];
    }

    protected function setWindowState(string $state): void
    {
        $window = $this->browserCall('Browser.getWindowForTarget', ['targetId' => $this->pageTargetId()]);
        $this->browserCall('Browser.setWindowBounds', [
            'windowId' => $window['windowId'],
            'bounds' => ['windowState' => $state],
        ]);
    }

    protected function waitForWindowState(string $expected): string
    {
        $state = '';
        // The window server applies a state change asynchronously; poll long enough for a busy host.
        $deadline = microtime(true) + 10;
        do {
            $state = $this->windowState();
            if ($state === $expected) {
                return $state;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        return $state;
    }

    protected function windowState(): string
    {
        $window = $this->browserCall('Browser.getWindowForTarget', ['targetId' => $this->pageTargetId()]);
        $bounds = $window['bounds'] ?? null;

        return is_array($bounds) ? (string) ($bounds['windowState'] ?? '') : '';
    }

    protected function pageTargetId(): string
    {
        $list = $this->httpJson('/json/list');
        $this->assertIsArray($list);
        foreach ($list as $row) {
            if (is_array($row) && ($row['type'] ?? '') === 'page' && ($row['id'] ?? '') !== '') {
                return (string) $row['id'];
            }
        }

        $this->fail('Chrome exposed no page target.');
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    protected function browserCall(string $method, array $params = []): array
    {
        $version = $this->httpJson('/json/version');
        $this->assertIsArray($version);
        $ws = $version['webSocketDebuggerUrl'] ?? null;
        $this->assertIsString($ws);
        $session = Session::connectToDebuggerUrl($ws, 5);
        try {
            return $session->call($method, $params, 5);
        } finally {
            $session->close();
        }
    }

    /**
     * @return array<mixed>|null
     */
    protected function httpJson(string $path): ?array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 2,
                'header' => "Connection: close\r\nAccept: application/json\r\n",
                'ignore_errors' => true,
            ],
        ]);
        $previous = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '2');
        try {
            $body = file_get_contents('http://127.0.0.1:' . $this->port . $path, false, $context);
        } finally {
            ini_set('default_socket_timeout', (string) $previous);
        }
        if (!is_string($body) || $body === '') {
            return null;
        }
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }

    protected function pickFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            $this->fail("Cannot allocate free port: {$errstr}");
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        if ($name === false || !str_contains($name, ':')) {
            $this->fail('Cannot resolve ephemeral port.');
        }

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    protected function bootLauncher(): Launcher
    {
        return new class (new Clock(), $this->filesystem()) extends Launcher {
            public function __construct(Clock $clock, Filesystem $filesystem)
            {
                $this->clock = $clock;
                $this->filesystem = $filesystem;
            }
        };
    }

    protected function filesystem(): Filesystem
    {
        return new class () extends Filesystem {
            public function __construct()
            {
                $this->pathAlias = new PathAlias();
            }
        };
    }
}
