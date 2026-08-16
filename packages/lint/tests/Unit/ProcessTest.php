<?php

declare(strict_types=1);

namespace Switon\Lint\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Switon\Core\ProcessTree;
use Switon\Lint\Exception;
use Switon\Lint\Process;
use Switon\Lint\Tests\TestCase;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;
use function usleep;

use const PHP_BINARY;

#[CoversClass(Process::class)]
class ProcessTest extends TestCase
{
    public function testRunCapturesStdoutAndExitCode(): void
    {
        $result = $this->make(Process::class)->run(
            [PHP_BINARY, '-r', 'echo "hi";'],
            static fn (): bool => false,
        );

        $this->assertSame(0, $result['exit']);
        $this->assertSame('hi', $result['stdout']);
        $this->assertSame('', $result['stderr']);
    }

    public function testRunRespectsAbort(): void
    {
        $result = $this->make(Process::class)->run(
            [PHP_BINARY, '-r', 'usleep(500000); echo "late";'],
            static function (): bool {
                static $n = 0;
                return ++$n > 1;
            },
        );

        $this->assertSame(130, $result['exit']);
        $this->assertStringNotContainsString('late', $result['stdout']);
    }

    public function testRunReturnsRealExitCode(): void
    {
        $result = $this->make(Process::class)->run(
            [PHP_BINARY, '-r', 'exit(3);'],
            static fn (): bool => false,
        );

        $this->assertSame(3, $result['exit']);
    }

    public function testAbortStopsDescendant(): void
    {
        $dir = sys_get_temp_dir() . '/switon-lint-proc-' . bin2hex(random_bytes(4));
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
        $pid = 0;
        try {
            $deadline = microtime(true) + 5;
            $result = $this->make(Process::class)->run(
                [PHP_BINARY, $parent, $pidFile, $child],
                static function () use ($pidFile, $deadline): bool {
                    if (is_file($pidFile) && (int) file_get_contents($pidFile) > 0) {
                        return true;
                    }
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('descendant did not start');
                    }

                    return false;
                },
            );

            $this->assertSame(130, $result['exit']);
            $pid = (int) file_get_contents($pidFile);
            usleep(200000);
            $this->assertFalse(ProcessTree::alive($pid), 'descendant still alive after lint abort');
        } finally {
            if ($pid > 0 && ProcessTree::alive($pid)) {
                ProcessTree::terminate($pid, 0.0);
            }
            @unlink($child);
            @unlink($parent);
            @unlink($pidFile);
            @rmdir($dir);
        }
    }

    public function testRunCapturesStderr(): void
    {
        $result = $this->make(Process::class)->run(
            [PHP_BINARY, '-r', 'fwrite(STDERR, "err");'],
            static fn (): bool => false,
        );

        $this->assertSame('err', $result['stderr']);
        $this->assertSame(0, $result['exit']);
    }

    public function testRunStopsAtTheDeadline(): void
    {
        $result = $this->make(Process::class, ['timeout' => 1])->run(
            [PHP_BINARY, '-r', 'sleep(30);'],
            static fn (): bool => false,
        );

        $this->assertSame(124, $result['exit']);
    }

    public function testRunKeepsOnlyTheOutputCeiling(): void
    {
        $result = $this->make(Process::class)->run(
            [PHP_BINARY, '-r', 'echo str_repeat("a", 20); usleep(200000); echo str_repeat("b", 20);'],
            static fn (): bool => false,
            null,
            8,
        );

        $this->assertSame(8, strlen($result['stdout']));
        $this->assertStringStartsWith('a', $result['stdout']);
    }

    public function testRunReportsWhenTheCommandIsEmpty(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Lint could not start process');

        $this->make(Process::class)->run([], static fn (): bool => false);
    }

    public function testRunReportsWhenTheProgramCannotStart(): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            $this->expectException(Exception::class);
            $this->expectExceptionMessage('Failed to start process "/no/such/switon-lint-bin"');

            $this->make(Process::class)->run(
                ['/no/such/switon-lint-bin'],
                static fn (): bool => false,
            );
        } finally {
            restore_error_handler();
        }
    }
}
