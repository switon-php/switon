<?php

declare(strict_types=1);

namespace Switon\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\ProcessTree;
use Switon\Core\Tests\TestCase;

use function bin2hex;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_resource;
use function microtime;
use function mkdir;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function random_bytes;
use function rmdir;
use function scandir;
use function shell_exec;
use function stream_get_contents;
use function stream_set_blocking;
use function str_contains;
use function sys_get_temp_dir;
use function trim;
use function unlink;
use function usleep;

use const PHP_BINARY;
use const PHP_OS_FAMILY;

#[CoversClass(ProcessTree::class)]
class ProcessTreeTest extends TestCase
{
    /** @var list<string> */
    protected array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempDirs) as $dir) {
            $this->removeTree($dir);
        }

        parent::tearDown();
    }

    public function testExitCodeKeepsRawWindowsStatus(): void
    {
        $this->assertSame(9009, ProcessTree::exitCode(9009, true));
        $this->assertSame(0xC0000135, ProcessTree::exitCode(0xC0000135, true));
        $this->assertSame(-1, ProcessTree::exitCode(-1, true));
    }

    public function testExitCodeUnpacksUnixWaitStatus(): void
    {
        $this->assertSame(35, ProcessTree::exitCode(9009, false));
        $this->assertSame(7, ProcessTree::exitCode(7 << 8, false));
        $this->assertSame(0, ProcessTree::exitCode(0, false));
        $this->assertSame(-1, ProcessTree::exitCode(-1, false));
    }

    public function testTerminateIgnoresNonPositivePid(): void
    {
        $slept = false;
        $sleep = static function () use (&$slept): void {
            $slept = true;
        };

        ProcessTree::terminate(0, 1.0, $sleep);
        ProcessTree::terminate(-3, 1.0, $sleep);

        $this->assertFalse($slept);
        $this->assertFalse(ProcessTree::alive(0));
    }

    public function testTerminateStopsParentChildAndGrandchild(): void
    {
        $dir = $this->tempDir();
        $grandFile = $dir . '/grand.pid';
        $childFile = $dir . '/child.pid';
        $grand = $dir . '/grand.php';
        $child = $dir . '/child.php';
        $parent = $dir . '/parent.php';
        file_put_contents($grand, <<<'PHP'
<?php
file_put_contents($argv[1], (string) getmypid());
sleep(60);
PHP);
        file_put_contents($child, <<<'PHP'
<?php
$grandFile = $argv[1];
$grand = $argv[2];
$myFile = $argv[3];
$pipes = [];
proc_open(
    [PHP_BINARY, $grand, $grandFile],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);
foreach ($pipes as $pipe) {
    if (is_resource($pipe)) {
        fclose($pipe);
    }
}
$deadline = microtime(true) + 5;
while (microtime(true) < $deadline && !is_file($grandFile)) {
    usleep(10000);
}
file_put_contents($myFile, (string) getmypid());
while (true) {
    sleep(30);
}
PHP);
        file_put_contents($parent, <<<'PHP'
<?php
$grandFile = $argv[1];
$childFile = $argv[2];
$child = $argv[3];
$grand = $argv[4];
$pipes = [];
proc_open(
    [PHP_BINARY, $child, $grandFile, $grand, $childFile],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);
foreach ($pipes as $pipe) {
    if (is_resource($pipe)) {
        fclose($pipe);
    }
}
$deadline = microtime(true) + 5;
while (microtime(true) < $deadline && !(is_file($grandFile) && is_file($childFile))) {
    usleep(10000);
}
echo "ready\n";
while (true) {
    sleep(30);
}
PHP);

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $parent, $grandFile, $childFile, $child, $grand],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertTrue(is_resource($process));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $root = 0;
        $childPid = 0;
        $grandPid = 0;
        try {
            $stdout = '';
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline && !str_contains($stdout, 'ready')) {
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                usleep(10000);
            }
            $this->assertStringContainsString('ready', $stdout);

            $status = proc_get_status($process);
            $root = is_int($status['pid'] ?? null) ? $status['pid'] : 0;
            $childPid = (int) file_get_contents($childFile);
            $grandPid = (int) file_get_contents($grandFile);
            $this->assertGreaterThan(0, $root);
            $this->assertGreaterThan(0, $childPid);
            $this->assertGreaterThan(0, $grandPid);
            $this->assertTrue(ProcessTree::alive($root));
            $this->assertTrue(ProcessTree::alive($childPid));
            $this->assertTrue(ProcessTree::alive($grandPid));

            $polls = 0;
            ProcessTree::terminate($root, 0.1, function (float $seconds) use (&$polls): void {
                $polls++;
                // Each pause is one poll step, never the whole grace window in one go.
                $this->assertGreaterThan(0, $seconds);
                $this->assertLessThanOrEqual(0.1, $seconds);
                usleep((int) ($seconds * 1_000_000));
            });
            $this->assertGreaterThan(0, $polls);

            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $process = null;
            usleep(200000);

            $this->assertFalse(ProcessTree::alive($childPid), 'child still alive');
            $this->assertFalse(ProcessTree::alive($grandPid), 'grandchild still alive');
            $this->assertFalse(ProcessTree::alive($root), 'parent still alive');
        } finally {
            foreach ([$grandPid, $childPid, $root] as $pid) {
                if ($pid > 0 && ProcessTree::alive($pid)) {
                    ProcessTree::terminate($pid, 0.0);
                }
            }
            if (is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (is_resource($pipes[2])) {
                fclose($pipes[2]);
            }
            if (is_resource($process)) {
                proc_close($process);
            }
        }
    }

    public function testTerminateReturnsAsSoonAsTheTreeExits(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Uses a Unix shell to spawn a cooperative process.');
        }

        // A cooperative process dies on SIGTERM, so the grace window must not be slept out.
        $pid = (int) trim((string) shell_exec('sleep 30 >/dev/null 2>&1 & echo $!'));

        try {
            $this->assertTrue(ProcessTree::alive($pid));

            $start = microtime(true);
            ProcessTree::terminate($pid, 3.0);
            $elapsed = microtime(true) - $start;

            $this->assertLessThan(1.0, $elapsed, 'grace must not be slept once the process is gone');
            $this->assertFalse(ProcessTree::alive($pid));
        } finally {
            if (ProcessTree::alive($pid)) {
                ProcessTree::terminate($pid, 0.0);
            }
        }
    }

    public function testTerminateStillKillsAnUncooperativeTree(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Uses a Unix shell to trap SIGTERM.');
        }

        // Ignores SIGTERM, so only the forced signal can stop it.
        $pid = (int) trim((string) shell_exec("sh -c 'trap \"\" TERM; sleep 30' >/dev/null 2>&1 & echo \$!"));

        try {
            $this->assertTrue(ProcessTree::alive($pid));

            $start = microtime(true);
            ProcessTree::terminate($pid, 0.3);
            $elapsed = microtime(true) - $start;

            $this->assertGreaterThanOrEqual(0.25, $elapsed, 'the grace window is still honoured');

            // The forced signal is delivered, but a killed pid stays visible until its new parent
            // reaps it, so give the reaper a moment instead of sampling once.
            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline && ProcessTree::alive($pid)) {
                usleep(20_000);
            }
            $this->assertFalse(ProcessTree::alive($pid), 'the forced signal must land');
        } finally {
            if (ProcessTree::alive($pid)) {
                ProcessTree::terminate($pid, 0.0);
            }
        }
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/switon-process-tree-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    protected function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
