<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Switon\Cdp\Exception;
use Switon\Cdp\Launcher;
use Switon\Cdp\Paths;
use Switon\Core\Clock;
use Switon\Core\Exception\CreateDirectoryFailedException;
use Switon\Core\Filesystem;
use Switon\Core\PathAlias;
use Switon\Core\ProcessTree;

use function bin2hex;
use function chmod;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_file;
use function mkdir;
use function random_bytes;
use function random_int;
use function rmdir;
use function str_contains;
use function sys_get_temp_dir;
use function unlink;
use function usleep;

use const PHP_OS_FAMILY;

class LauncherEnsureTest extends TestCase
{
    #[Test]
    public function ensureStartsAListenerAndForegroundsIt(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix launch is not the Windows path.');
        }

        $dir = $this->workdir();
        $launcher = $this->launcher();
        $port = $this->freePort($launcher);
        $pidFile = $dir . '/pid';
        putenv('XDEBUG_MODE=off');
        try {
            $launcher->ensure($port, $dir, true, $dir . '/stand-in.php', 8.0, [
                '--proxy-server=http://127.0.0.1:9',
                '  ',
                '--pidfile=' . $pidFile,
            ]);

            $this->assertTrue($launcher->portOpen($port));
            $this->assertTrue($launcher->ownsProcess($port));
            $args = $this->args($pidFile);
            $this->assertTrue(str_contains($args, '--headless=new'));
            $this->assertTrue(str_contains($args, '--proxy-server=http://127.0.0.1:9'));
            $this->assertTrue(str_contains($args, 'about:blank'));

            $launcher->foreground($port);
            $launcher->stopOwnedProcess($port);
            usleep(200000);

            $this->assertFalse($launcher->ownsProcess($port));
            $this->assertFalse($launcher->portOpen($port));
        } finally {
            $this->cleanup($dir, $pidFile, $launcher, $port);
        }
    }

    #[Test]
    public function aHeadedLaunchOmitsTheHeadlessFlag(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix launch is not the Windows path.');
        }
        if (PHP_OS_FAMILY === 'Linux' && (getenv('DISPLAY') ?: '') === '' && (getenv('WAYLAND_DISPLAY') ?: '') === '') {
            $this->markTestSkipped('Headed launch needs a display on Linux.');
        }

        $dir = $this->workdir();
        $launcher = $this->launcher();
        $port = $this->freePort($launcher);
        $pidFile = $dir . '/pid';
        putenv('XDEBUG_MODE=off');
        try {
            $launcher->ensure($port, $dir, false, $dir . '/stand-in.php', 8.0, ['--pidfile=' . $pidFile]);

            $this->assertFalse(str_contains($this->args($pidFile), '--headless'));
            $this->assertTrue($launcher->ownsProcess($port));
        } finally {
            $this->cleanup($dir, $pidFile, $launcher, $port);
        }
    }

    #[Test]
    public function ensureLeavesAPortThatIsAlreadyOpen(): void
    {
        $launcher = $this->launcher();
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server);
        $port = (int) substr((string) stream_socket_get_name($server, false), (int) strrpos((string) stream_socket_get_name($server, false), ':') + 1);
        try {
            $launcher->ensure($port, '/unused', true, '/missing/binary', 1.0);

            $this->assertFalse($launcher->ownsProcess($port));
            $this->assertTrue($launcher->portOpen($port));
        } finally {
            fclose($server);
        }
    }

    #[Test]
    public function launchRejectsAnEmptyProfileAndADirectoryItCannotCreate(): void
    {
        $launcher = $this->launcher();

        try {
            $launcher->ensure(1, '', true, '/missing/binary', 1.0);
            $this->fail('An empty profile must be rejected.');
        } catch (Exception $e) {
            $this->assertStringContainsString('must not be empty', $e->getMessage());
        }

        $broken = $this->launcher();
        $broken->useFilesystem(new class () extends Filesystem {
            public function __construct()
            {
                $this->pathAlias = new PathAlias();
            }

            public function isDir(string $dir): bool
            {
                return false;
            }

            public function mkdir(string $dir, int $mode = 0755): void
            {
                CreateDirectoryFailedException::raise('denied');
            }
        });

        $dir = sys_get_temp_dir() . '/switon-cdp-denied';
        try {
            $broken->ensure($this->freePort($broken), $dir, true, '/missing/binary', 1.0);
            $this->fail('A profile directory that cannot be created must be reported.');
        } catch (Exception $e) {
            $this->assertStringContainsString(Paths::displayPath($dir), $e->getMessage());
            $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';
            if ($home !== '') {
                $this->assertStringNotContainsString($home, $e->getMessage());
            }
        }
    }

    #[Test]
    public function launchReportsAProcessThatExitsOrNeverListens(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix launch is not the Windows path.');
        }

        $dir = $this->workdir();
        $launcher = $this->launcher();
        $pidFile = $dir . '/pid';
        putenv('XDEBUG_MODE=off');
        try {
            $exitPort = $this->freePort($launcher);
            try {
                $launcher->ensure($exitPort, $dir, true, $dir . '/stand-in.php', 1.0, [
                    '--exit',
                    '--pidfile=' . $pidFile,
                ]);
                $this->fail('A process that exits must not count as a browser.');
            } catch (Exception $e) {
                $this->assertStringContainsString('exited during startup', $e->getMessage());
            }
            $this->assertFalse($launcher->ownsProcess($exitPort));

            $port = $this->freePort($launcher);
            try {
                $launcher->ensure($port, $dir, true, $dir . '/stand-in.php', 1.0, [
                    '--hang',
                    '--pidfile=' . $pidFile,
                ]);
                $this->fail('A process that never listens must time out.');
            } catch (Exception $e) {
                $this->assertStringContainsString('did not open', $e->getMessage());
            }
            $this->assertFalse($launcher->ownsProcess($port));
        } finally {
            $this->cleanup($dir, $pidFile, $launcher, 0);
        }
    }

    #[Test]
    public function foregroundAndStopIgnoreAPortThisLauncherDoesNotHold(): void
    {
        $launcher = $this->launcher();
        $port = $this->freePort($launcher);

        $launcher->foreground($port);
        $launcher->stopOwnedProcess($port);
        $launcher->seed($port, 2_000_000_000);
        $launcher->stopOwnedProcess($port);

        $this->assertFalse($launcher->ownsProcess($port));
        $this->assertFalse($launcher->portOpen($port));
    }

    private function workdir(): string
    {
        $dir = sys_get_temp_dir() . '/switon-cdp-launch-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/stand-in.php', '#!' . PHP_BINARY . "\n" . <<<'PHP'
<?php
$port = 0;
$pidfile = '';
$mode = 'listen';
foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--remote-debugging-port=')) {
        $port = (int) substr($arg, strlen('--remote-debugging-port='));
    } elseif (str_starts_with($arg, '--pidfile=')) {
        $pidfile = substr($arg, strlen('--pidfile='));
    } elseif ($arg === '--exit' || $arg === '--hang') {
        $mode = $arg;
    }
}
if ($pidfile !== '') {
    file_put_contents($pidfile, (string) getmypid());
}
if ($mode === '--exit') {
    exit(0);
}
if ($mode === '--hang') {
    while (true) {
        sleep(30);
    }
}
$errno = 0;
$errstr = '';
$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if ($server === false) {
    exit(1);
}
file_put_contents($pidfile . '.args', implode("\n", array_slice($argv, 1)));
while (true) {
    sleep(30);
}
PHP);
        chmod($dir . '/stand-in.php', 0755);

        return $dir;
    }

    private function launcher(): Launcher
    {
        return new class () extends Launcher {
            public function __construct()
            {
                $this->clock = new Clock();
                $this->filesystem = new class () extends Filesystem {
                    public function __construct()
                    {
                        $this->pathAlias = new PathAlias();
                    }
                };
            }

            public function useFilesystem(Filesystem $filesystem): void
            {
                $this->filesystem = $filesystem;
            }

            public function seed(int $port, int $pid): void
            {
                $this->ownedPids[$port] = $pid;
            }
        };
    }

    private function freePort(Launcher $launcher): int
    {
        for ($i = 0; $i < 20; $i++) {
            $port = random_int(20000, 45000);
            if (!$launcher->portOpen($port)) {
                return $port;
            }
        }

        $this->fail('No free TCP port was found.');
    }

    private function args(string $pidFile): string
    {
        $path = $pidFile . '.args';
        $deadline = microtime(true) + 2;
        while (microtime(true) < $deadline && !is_file($path)) {
            usleep(1000);
        }
        $this->assertTrue(is_file($path));

        return (string) file_get_contents($path);
    }

    private function cleanup(string $dir, string $pidFile, Launcher $launcher, int $port): void
    {
        if ($port > 0) {
            $launcher->stopOwnedProcess($port);
        }
        $pid = is_file($pidFile) ? (int) file_get_contents($pidFile) : 0;
        if ($pid > 0 && ProcessTree::alive($pid)) {
            ProcessTree::terminate($pid, 0.0);
        }
        @unlink($dir . '/stand-in.php');
        @unlink($pidFile);
        @unlink($pidFile . '.args');
        @rmdir($dir);
    }
}
