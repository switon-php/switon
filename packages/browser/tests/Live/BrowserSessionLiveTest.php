<?php

declare(strict_types=1);

namespace Switon\Browser\Tests\Live;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Switon\Cdp\HeadedChrome;
use Switon\Cdp\Launcher;
use Switon\Cdp\Paths;
use Switon\Browser\HeadedBrowser;
use Switon\Core\Clock;
use Switon\Core\Filesystem;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAlias;

use function fclose;
use function file_get_contents;
use function file_put_contents;
use function fsockopen;
use function mkdir;
use function preg_match;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;
use function usleep;

use const PHP_BINARY;

/**
 * Live session loop on headed Chrome (navigate → snapshot → click → screenshot).
 *
 * Skips when Chrome is missing. Uses an ephemeral profile, a free debugging port, and a local page.
 */
class BrowserSessionLiveTest extends TestCase
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

        $this->userDataDir = sys_get_temp_dir() . '/switon-browser-session-' . getmypid();
        $this->port = $this->pickFreePort();
        $this->launcher = new class (new Clock(), $this->filesystem()) extends Launcher {
            public function __construct(Clock $clock, Filesystem $filesystem)
            {
                $this->clock = $clock;
                $this->filesystem = $filesystem;
            }
        };
    }

    protected function tearDown(): void
    {
        $this->launcher?->stopOwnedProcess($this->port);
        parent::tearDown();
    }

    #[Test]
    public function navigateSnapshotAndClickALocalButton(): void
    {
        [$proc, $url, $doc] = $this->servePage();
        $browser = $this->makeBrowser();
        try {
            $browser->navigate($url);
            $snap = $browser->snapshot();
            $this->assertNotSame('', $snap);
            $this->assertMatchesRegularExpression('/\[e\d+\]\s+(\(offscreen\)\s+)?button/i', $snap);
            $this->assertSame(1, preg_match('/\[(e\d+)\][^\n]*button/i', $snap, $match));

            $browser->click($match[1]);
            $after = $browser->snapshot();
            $this->assertStringContainsString('clicked', $after);

            $shot = $browser->takeScreenshot(false);
            $this->assertFileExists($shot['path']);
            $this->assertGreaterThan(100, $shot['bytes']);
            $header = (string) file_get_contents($shot['path'], false, null, 0, 8);
            $this->assertSame("\x89PNG\r\n\x1a\n", $header);
            $this->assertStringContainsString('127.0.0.1', $shot['url']);
        } finally {
            $browser->closePage();
            proc_terminate($proc);
            proc_close($proc);
            @unlink($doc . '/index.html');
            @rmdir($doc);
        }
    }

    /**
     * @return array{0: resource, 1: string, 2: string}
     */
    protected function servePage(): array
    {
        $doc = sys_get_temp_dir() . '/switon-browser-page-' . getmypid();
        mkdir($doc, 0700, true);
        file_put_contents($doc . '/index.html', <<<'HTML'
<!doctype html>
<meta charset="utf-8">
<title>local</title>
<button id="go">Go</button>
<p id="out"></p>
<script>
document.getElementById('go').addEventListener('click', function () {
  document.getElementById('out').textContent = 'clicked';
});
</script>
HTML);
        $port = $this->pickFreePort();
        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $doc],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        $deadline = microtime(true) + 2;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($fp !== false) {
                fclose($fp);
                break;
            }
            usleep(20000);
        }

        return [$proc, 'http://127.0.0.1:' . $port . '/', $doc];
    }

    protected function makeBrowser(): HeadedBrowser
    {
        $launcher = $this->launcher;
        $this->assertInstanceOf(Launcher::class, $launcher);
        $filesystem = $this->filesystem();
        $chrome = new class ($launcher, new Clock(), $this->userDataDir, $this->port) extends HeadedChrome {
            public function __construct(Launcher $launcher, Clock $clock, string $userDataDir, int $port)
            {
                $this->launcher = $launcher;
                $this->clock = $clock;
                $this->userDataDir = $userDataDir;
                $this->port = $port;
                $this->autoLaunch = true;
                $this->timeout = 60.0;
                // CI and agent sandboxes block Chrome's own sandbox, which kills the GPU process at
                // startup. The package default stays sandboxed; only this test opts out.
                $this->args = ['--no-sandbox', '--disable-dev-shm-usage'];
            }
        };

        return new class ($chrome, $filesystem, new PathAlias(), $this->userDataDir . '/shots') extends HeadedBrowser {
            public function __construct(
                HeadedChrome $chrome,
                FilesystemInterface $filesystem,
                PathAlias $pathAlias,
                string $screenshotDir,
            ) {
                $this->chrome = $chrome;
                $this->filesystem = $filesystem;
                $this->pathAlias = $pathAlias;
                $this->screenshotDir = $screenshotDir;
            }
        };
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

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    protected function filesystem(): FilesystemInterface
    {
        return new class () extends Filesystem {
            public function __construct()
            {
                $this->pathAlias = new PathAlias();
            }
        };
    }
}
