<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Switon\Cdp\Launcher;
use Switon\Core\Clock;
use Switon\Core\ProcessTree;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function trim;
use function unlink;
use function usleep;

use const PHP_BINARY;
use const PHP_OS_FAMILY;

#[CoversClass(Launcher::class)]
class LauncherUnixStartTest extends TestCase
{
    public function testListenerCommandLineReportsTheHeadlessFlag(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix listener lookup is not the Windows path.');
        }

        $launcher = $this->launcher();
        if ($launcher->commandLineOf(getmypid()) === '') {
            $this->markTestSkipped('Process command lines are not readable here; `ps` is blocked.');
        }

        $dir = sys_get_temp_dir() . '/switon-cdp-listen-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $script = $dir . '/listen.php';
        $addressFile = $dir . '/address';
        file_put_contents($script, <<<'PHP'
<?php
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
file_put_contents($argv[1], (string) stream_socket_get_name($server, false));
while (true) {
    sleep(30);
}
PHP);
        $pid = (int) trim((string) $launcher->unix([PHP_BINARY, $script, $addressFile, '--headless=new']));
        try {
            $address = '';
            $deadline = microtime(true) + 2;
            while (microtime(true) < $deadline && !is_file($addressFile)) {
                usleep(1000);
            }
            $this->assertTrue(is_file($addressFile));
            $address = trim((string) file_get_contents($addressFile));
            $port = (int) substr($address, (int) strrpos($address, ':') + 1);

            $this->assertTrue($launcher->listenerIsHeadless($port));
        } finally {
            if ($pid > 0 && ProcessTree::alive($pid)) {
                ProcessTree::terminate($pid, 0.0);
            }
            @unlink($script);
            @unlink($addressFile);
            @rmdir($dir);
        }
    }

    public function testUnixLaunchBackgroundsProcessAndTreeStopKillsIt(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix shell launch is not the Windows path.');
        }

        $launcher = $this->launcher();

        $pid = (int) trim((string) $launcher->unix([PHP_BINARY, '-r', 'sleep(30);']));
        try {
            $this->assertGreaterThan(0, $pid);
            $this->assertTrue(ProcessTree::alive($pid));
            ProcessTree::terminate($pid, 0.0);
            usleep(200000);
            $this->assertFalse(ProcessTree::alive($pid), 'background pid still alive');
        } finally {
            if ($pid > 0 && ProcessTree::alive($pid)) {
                ProcessTree::terminate($pid, 0.0);
            }
        }
    }

    public function testUnixStopKillsDescendant(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unix shell launch is not the Windows path.');
        }

        $dir = sys_get_temp_dir() . '/switon-chrome-launch-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $pidFile = $dir . '/child.pid';
        $child = $dir . '/child.php';
        $parent = $dir . '/parent.php';
        file_put_contents($child, <<<'PHP'
<?php
file_put_contents($argv[1], (string) getmypid());
sleep(60);
PHP);
        file_put_contents($parent, <<<'PHP'
<?php
$pipes = [];
proc_open(
    [PHP_BINARY, $argv[2], $argv[1]],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);
foreach ($pipes as $pipe) {
    if (is_resource($pipe)) {
        fclose($pipe);
    }
}
while (true) {
    sleep(30);
}
PHP);

        $launcher = $this->launcher();
        $root = (int) trim((string) $launcher->unix([PHP_BINARY, $parent, $pidFile, $child]));
        $childPid = 0;
        try {
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline && !(is_file($pidFile) && (int) file_get_contents($pidFile) > 0)) {
                usleep(10000);
            }
            $this->assertGreaterThan(0, $root);
            $this->assertTrue(is_file($pidFile));
            $childPid = (int) file_get_contents($pidFile);
            $this->assertGreaterThan(0, $childPid);
            $this->assertTrue(ProcessTree::alive($root));
            $this->assertTrue(ProcessTree::alive($childPid));

            $launcher->own($root);
            $launcher->stop();
            usleep(200000);

            $this->assertFalse(ProcessTree::alive($childPid), 'descendant still alive after Chrome stop');
            $this->assertFalse(ProcessTree::alive($root), 'launched pid still alive after Chrome stop');
            $this->assertFalse($launcher->owns());
        } finally {
            foreach ([$childPid, $root] as $pid) {
                if ($pid > 0 && ProcessTree::alive($pid)) {
                    ProcessTree::terminate($pid, 0.0);
                }
            }
            @unlink($child);
            @unlink($parent);
            @unlink($pidFile);
            @rmdir($dir);
        }
    }

    protected function launcher(): Launcher
    {
        return new class () extends Launcher {
            public function __construct()
            {
                $this->clock = new Clock();
            }

            /** @param list<string> $args */
            public function unix(array $args): ?string
            {
                return $this->startOnUnix($args);
            }

            public function own(int $pid): void
            {
                $this->ownedPids[59222] = $pid;
            }

            public function stop(): void
            {
                $this->stopOwnedProcess(59222);
            }

            public function owns(): bool
            {
                return $this->ownsProcess(59222);
            }

            public function commandLineOf(int $pid): string
            {
                return $this->commandLine($pid);
            }
        };
    }
}
