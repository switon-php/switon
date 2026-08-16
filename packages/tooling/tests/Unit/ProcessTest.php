<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\AgentInterface;
use Switon\Ai\Event\ToolResultDelta;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolInterface;
use Switon\Ai\ToolSchema;
use Switon\Tooling\Exception;
use Switon\Tooling\Process;
use Switon\Tooling\Spiller;
use Switon\Tooling\Tests\Fixtures\ToolTestAgent;
use Switon\Tooling\Tests\ToolTestCase;

use function bin2hex;
use function chmod;
use function ctype_digit;
use function exec;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function is_file;
use function mkdir;
use function posix_kill;
use function random_bytes;
use function rmdir;
use function str_contains;
use function sys_get_temp_dir;
use function trim;
use function unlink;
use function usleep;

use const PHP_BINARY;
use const PHP_OS_FAMILY;

#[CoversClass(Process::class)]
class ProcessTest extends ToolTestCase
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

    public function testDecodeClosedExitCodeUsesRawCodeOnWindowsSemantics(): void
    {
        $subprocess = new class () extends Process {
            protected function procCloseReturnsRawExitCode(): bool
            {
                return true;
            }

            public function exposeDecode(int $closed): int
            {
                return $this->decodeClosedExitCode($closed);
            }
        };

        $this->assertSame(9009, $subprocess->exposeDecode(9009));
        $this->assertSame(0xC0000135, $subprocess->exposeDecode(0xC0000135));
        $this->assertSame(-1, $subprocess->exposeDecode(-1));
    }

    public function testDecodeClosedExitCodeUnpacksUnixWaitStatus(): void
    {
        $subprocess = new class () extends Process {
            protected function procCloseReturnsRawExitCode(): bool
            {
                return false;
            }

            public function exposeDecode(int $closed): int
            {
                return $this->decodeClosedExitCode($closed);
            }
        };

        $this->assertSame(35, $subprocess->exposeDecode(9009));
        $this->assertSame(7, $subprocess->exposeDecode(7 << 8));
        $this->assertSame(0, $subprocess->exposeDecode(0));
        $this->assertSame(-1, $subprocess->exposeDecode(-1));
    }

    public function testCollectLinesStripsCarriageReturn(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('fake printer script needs a POSIX sh');
        }

        $binDir = $this->tempDir();
        $printer = $binDir . '/printer';
        file_put_contents($printer, "#!/bin/sh\nprintf 'rel/a.txt\\r\\nrel/b.txt\\r\\n'\n");
        chmod($printer, 0755);

        $agent = $this->hostWithSpill();
        $process = $this->make(Process::class, [
            'agent' => $agent,
            'call' => new ToolCall(id: 'c1'),
        ]);
        $result = $process->start(
            command: [$printer],
            cwd: $agent->workspace(),
            timeout: $process->timeout(),
        )->lines(new ToolResultDelta($this->tool(), 'c1', 'lines', $agent, ''), 100);

        $this->assertSame(['rel/a.txt', 'rel/b.txt'], $result['lines']);
        foreach ($result['lines'] as $line) {
            $this->assertStringNotContainsString("\r", $line);
        }
    }

    /**
     * Parent PHP starts a grandchild that records its pid, then this process stops the tree.
     * The branch is the host OS: Unix signals, Windows `taskkill /T`.
     */
    public function testTerminateTreeStopsDescendantOnThisOs(): void
    {
        $dir = $this->tempDir();
        $pidFile = $dir . '/grandchild.pid';
        $child = $dir . '/child.php';
        $parent = $dir . '/parent.php';
        file_put_contents($child, <<<'PHP'
<?php
file_put_contents($argv[1], (string) getmypid());
sleep(60);
PHP);
        file_put_contents($parent, <<<'PHP'
<?php
$pidFile = $argv[1];
$child = $argv[2];
$pipes = [];
$proc = proc_open(
    [PHP_BINARY, $child, $pidFile],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);
foreach ($pipes as $pipe) {
    if (is_resource($pipe)) {
        fclose($pipe);
    }
}
$deadline = microtime(true) + 5;
while (microtime(true) < $deadline && !is_file($pidFile)) {
    usleep(10000);
}
echo "ready\n";
while (true) {
    sleep(30);
}
PHP);

        $agent = $this->hostWithSpill();
        $pid = 0;
        try {
            $process = $this->make(Process::class, [
                'agent' => $agent,
                'call' => new ToolCall(id: 'tree'),
            ]);
            $result = $process->start(
                command: [PHP_BINARY, $parent, $pidFile, $child],
                cwd: $agent->workspace(),
                timeout: 10,
            )->output(new ToolResultDelta($this->tool(), 'tree', 'tree', $agent, ''), 'ready');

            $this->assertTrue($result['matched'] ?? false);
            $recorded = is_file($pidFile) ? trim((string) file_get_contents($pidFile)) : '';
            $this->assertTrue($recorded !== '' && ctype_digit($recorded));
            $pid = (int) $recorded;
            usleep(300000);
            $this->assertFalse($this->pidAlive($pid), 'descendant pid ' . $pid . ' still alive after terminateTree');
        } finally {
            if ($pid > 0 && $this->pidAlive($pid)) {
                $this->killPid($pid);
            }
        }
    }

    protected function pidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            exec('tasklist /FI "PID eq ' . $pid . '" /NH', $output);

            return str_contains(implode("\n", $output), (string) $pid);
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        $output = [];
        exec('ps -p ' . $pid, $output, $code);

        return $code === 0;
    }

    protected function killPid(int $pid): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /F /T /PID ' . $pid . ' 2>NUL');

            return;
        }

        if (function_exists('posix_kill')) {
            @posix_kill($pid, 9);
        }
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/switon-subprocess-test-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    public function testLinesRejectsALimitBelowOne(): void
    {
        $process = $this->make(Process::class, [
            'agent' => $this->hostWithSpill(),
            'call' => new ToolCall(id: 'c1'),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('maxLines must be at least 1');

        $process->lines(new ToolResultDelta($this->tool(), 'c1', 'lines', $this->hostWithSpill(), ''), 0);
    }

    public function testLinesKeepsATailWithoutANewline(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('fake printer script needs a POSIX sh');
        }

        $binDir = $this->tempDir();
        $printer = $binDir . '/printer';
        file_put_contents($printer, "#!/bin/sh\nprintf 'tail'\n");
        chmod($printer, 0755);

        $agent = $this->hostWithSpill();
        $result = $this->make(Process::class, [
            'agent' => $agent,
            'call' => new ToolCall(id: 'c1'),
        ])->start([$printer], $agent->workspace(), 5)
            ->lines(new ToolResultDelta($this->tool(), 'c1', 'lines', $agent, ''), 10);

        $this->assertSame(['tail'], $result['lines']);
    }

    public function testLinesStopsWhenTheCallIsCancelled(): void
    {
        $agent = $this->hostWithSpill();
        $call = new ToolCall(id: 'c1');
        $call->cancelled = true;

        $result = $this->make(Process::class, [
            'agent' => $agent,
            'call' => $call,
        ])->start([PHP_BINARY, '-r', 'sleep(30);'], $agent->workspace(), 5)
            ->lines(new ToolResultDelta($this->tool(), 'c1', 'lines', $agent, ''), 10);

        $this->assertTrue($result['cancelled']);
        $this->assertSame(130, $result['exit']);
    }

    public function testStartRejectsAMissingDirectory(): void
    {
        $process = $this->make(Process::class, [
            'agent' => $this->hostWithSpill(),
            'call' => new ToolCall(id: 'c1'),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Working directory does not exist.');

        $process->start([PHP_BINARY, '-r', 'echo 1;'], '/no/such/switon-tooling-dir', 5);
    }

    public function testOutputHaltsWhenTheCallbackSaysSo(): void
    {
        $agent = $this->hostWithSpill();
        $result = $this->make(Process::class, [
            'agent' => $agent,
            'call' => new ToolCall(id: 'c1'),
        ])->start([PHP_BINARY, '-r', 'fwrite(STDOUT, "hello");'], $agent->workspace(), 5)
            ->output(
                new ToolResultDelta($this->tool(), 'c1', 'out', $agent, ''),
                null,
                static fn (string $buffer): bool => str_contains($buffer, 'hello'),
            );

        $this->assertTrue($result['halted']);
    }

    public function testOutputMarksTheCeilingWhenTheBufferFillsExactly(): void
    {
        $agent = $this->hostWithSpill();
        $result = $this->make(FiveByteProcess::class, [
            'agent' => $agent,
            'call' => new ToolCall(id: 'c1'),
        ])->start([PHP_BINARY, '-r', 'fwrite(STDOUT, "hello");'], $agent->workspace(), 5)
            ->output(new ToolResultDelta($this->tool(), 'c1', 'out', $agent, ''));

        $this->assertStringContainsString(Spiller::ceilingExceeded(5), $result['stdout']);
    }

    public function testWaitReturnsImmediatelyForANonPositiveDelay(): void
    {
        $process = $this->make(Process::class, [
            'agent' => $this->hostWithSpill(),
            'call' => new ToolCall(id: 'c1'),
        ]);
        $started = microtime(true);

        $process->wait(0);

        $this->assertLessThan(0.05, microtime(true) - $started);
    }

    protected function hostWithSpill(): ToolTestAgent
    {
        $dir = $this->tempDir();
        mkdir($dir . '/tool-result', 0700, true);

        return ToolTestAgent::in($dir);
    }

    protected function tool(): ToolInterface
    {
        return new class () implements ToolInterface {
            public function schema(string $name): ?ToolSchema
            {
                return null;
            }

            public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
            {
                unset($agent, $call, $arguments);

                return null;
            }

            /**
             * @param array<string, mixed> $arguments
             */
            public function isReadonly(string $name, array $arguments): bool
            {
                unset($name, $arguments);

                return false;
            }
        };
    }
}

class FiveByteProcess extends Process
{
    public function ceilingBytes(): int
    {
        return 5;
    }
}
