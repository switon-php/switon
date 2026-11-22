<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Event\ToolResultDelta;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolInterface;
use Switon\Core\ProcessTree;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Tooling\ArgumentBinder;
use Switon\Tooling\Exception;
use Switon\Tooling\Process;
use Switon\Tooling\ProcessInterface;
use Switon\Tooling\Spiller;
use Switon\Tooling\Tests\Fixtures\ToolTestAgent;
use Switon\Tooling\Tests\ToolTestCase;
use Switon\Tooling\Tool\BashTool;
use Throwable;

use const PHP_OS_FAMILY;

#[CoversClass(BashTool::class)]
#[CoversClass(Process::class)]
class BashToolTest extends ToolTestCase
{
    /** @var list<string> */
    protected array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempDirs) as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    protected function callTool(
        ToolInterface $tool,
        string $name,
        array $arguments = [],
        ?string $workspace = null,
        ?string $datadir = null,
        string $callId = 'test',
    ): mixed {
        if ($tool instanceof BashTool) {
            $arguments = $this->make(ArgumentBinder::class)->bind($tool->schema($name), $arguments);
        }

        return parent::callTool($tool, $name, $arguments, $workspace, $datadir, $callId);
    }

    public function testCallRunsCommand(): void
    {
        $tool = $this->make(BashTool::class);

        $result = $this->callTool($tool, 'bash', ['command' => 'echo hello']);

        $this->assertSame(0, $result['exit']);
        $this->assertSame("hello\n", $result['stdout']);
        $this->assertSame('', $result['stderr']);
    }

    public function testSchemaRoutesFileOperationsToTools(): void
    {
        $schema = $this->make(BashTool::class)->schema('bash');

        $this->assertStringContainsString('fresh shell', $schema->definition['description']);
        $this->assertStringContainsString('Prefer a dedicated tool', $schema->definition['description']);
        $this->assertStringContainsString('`rm` tool for deletes', $schema->definition['description']);
        $this->assertStringContainsString('Keep command output small', $schema->definition['description']);
        $this->assertStringContainsString('spilled for the recall tool', $schema->definition['description']);
        $this->assertStringContainsString('@thread/', $schema->definition['description']);
        $this->assertDoesNotMatchRegularExpression('/\bread\b/', $schema->definition['description']);
        $this->assertStringNotContainsString('NEVER', $schema->definition['description']);
        $this->assertStringNotContainsString('bash -lc', $schema->definition['description']);
        $this->assertStringNotContainsString('Tool id', $schema->definition['description']);
        $this->assertArrayNotHasKey('description', $schema->definition['inputSchema']['properties']['command']);
        $this->assertArrayNotHasKey('description', $schema->definition['inputSchema']['properties']);
        $this->assertSame(
            'Absolute directory. Defaults to workspace, else the system temp directory.',
            $schema->definition['inputSchema']['properties']['cwd']['description'],
        );
        $this->assertSame('Return when stdout/stderr matches this regex.', $schema->definition['inputSchema']['properties']['pattern']['description']);
        $this->assertSame(
            'Wall-clock timeout in seconds.',
            $schema->definition['inputSchema']['properties']['timeout']['description'],
        );
        $this->assertSame(3600, $schema->definition['inputSchema']['properties']['timeout']['default']);
        $this->assertArrayNotHasKey('exclusiveMinimum', $schema->definition['inputSchema']['properties']['timeout']);
        $this->assertArrayNotHasKey('maximum', $schema->definition['inputSchema']['properties']['timeout']);
        $this->assertSame(
            'Detach; process is not managed. Optional pattern: wait for match, keep alive, return pid.',
            $schema->definition['inputSchema']['properties']['daemon']['description'],
        );
        $this->assertSame(false, $schema->definition['inputSchema']['properties']['daemon']['default']);
    }

    public function testCallAllowsPipeline(): void
    {
        $result = $this->callTool($this->make(BashTool::class), 'bash', ['command' => 'echo hello | wc -c']);

        $this->assertSame(0, $result['exit']);
        $this->assertMatchesRegularExpression('/^\s*6\s*$/', $result['stdout']);
    }

    public function testCallStopsOnPatternMatchAndKillsProcess(): void
    {
        $marker = $this->tempDir() . '/after-ready';
        $previous = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '-1');

        try {
            $started = microtime(true);
            $result = $this->callTool($this->make(BashTool::class), 'bash', [
                'command' => 'echo ready; sleep 1; touch ' . escapeshellarg($marker) . '; sleep 30',
                'pattern' => 'ready',
            ]);
            $elapsed = microtime(true) - $started;
        } finally {
            ini_set('default_socket_timeout', (string)$previous);
        }

        $this->assertTrue($result['matched'] ?? false);
        $this->assertArrayNotHasKey('timeout', $result);
        $this->assertArrayNotHasKey('daemon', $result);
        $this->assertArrayNotHasKey('pid', $result);
        $this->assertStringContainsString('ready', $result['stdout']);
        $this->assertLessThan(4.0, $elapsed);
        usleep(1_200_000);
        $this->assertFileDoesNotExist($marker);
    }

    public function testCallRejectsInvalidPattern(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid pattern regex.');

        $this->callTool($this->make(BashTool::class), 'bash', [
            'command' => 'echo x',
            'pattern' => '(unclosed',
        ]);
    }

    public function testCallEmptyPatternIsIgnored(): void
    {
        $result = $this->callTool($this->make(BashTool::class), 'bash', [
            'command' => 'echo hello',
            'pattern' => '',
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertSame("hello\n", $result['stdout']);
        $this->assertArrayNotHasKey('matched', $result);
    }

    public function testCallDaemonDetachesAndReturnsPid(): void
    {
        $started = microtime(true);
        $result = $this->callTool($this->make(BashTool::class), 'bash', [
            'command' => 'sleep 30',
            'daemon' => true,
        ]);
        $elapsed = microtime(true) - $started;

        $this->assertTrue($result['daemon'] ?? false);
        $this->assertArrayHasKey('pid', $result);
        $this->assertGreaterThan(0, $result['pid']);
        $this->assertSame('', $result['stdout']);
        $this->assertArrayNotHasKey('matched', $result);
        $this->assertLessThan(4.0, $elapsed);

        $this->assertProcessAliveThenKill($result['pid']);
    }

    public function testDaemonLauncherQuotesSingleQuote(): void
    {
        $result = $this->callTool($this->make(BashTool::class), 'bash', [
            'command' => 'echo "it\'s-ready"',
            'pattern' => "it's-ready",
            'daemon' => true,
        ]);

        try {
            $this->assertTrue($result['matched'] ?? false);
            $this->assertStringContainsString("it's-ready", $result['stdout']);
            $this->assertGreaterThan(0, $result['pid'] ?? 0);
        } finally {
            if (($result['pid'] ?? 0) > 0) {
                ProcessTree::terminate((int) $result['pid'], 0.0);
            }
        }
    }

    public function testCallDaemonWithPatternWaitsAndKeepsProcess(): void
    {
        $started = microtime(true);
        $result = $this->callTool($this->make(BashTool::class), 'bash', [
            'command' => 'echo ready; sleep 30',
            'pattern' => 'ready',
            'daemon' => true,
        ]);
        $elapsed = microtime(true) - $started;

        $this->assertTrue($result['daemon'] ?? false);
        $this->assertTrue($result['matched'] ?? false);
        $this->assertArrayHasKey('pid', $result);
        $this->assertGreaterThan(0, $result['pid']);
        $this->assertStringContainsString('ready', $result['stdout']);
        $this->assertArrayNotHasKey('timeout', $result);
        $this->assertArrayNotHasKey('cancelled', $result);
        $this->assertLessThan(4.0, $elapsed);

        $this->assertProcessAliveThenKill($result['pid']);
    }

    public function testCallDaemonWithPatternWaitsForDelayedMatch(): void
    {
        $started = microtime(true);
        $result = $this->callTool($this->make(BashTool::class), 'bash', [
            'command' => 'sleep 0.4; echo ready; sleep 30',
            'pattern' => 'ready',
            'daemon' => true,
        ]);
        $elapsed = microtime(true) - $started;

        $this->assertTrue($result['matched'] ?? false);
        $this->assertStringContainsString('ready', $result['stdout']);
        $this->assertGreaterThanOrEqual(0.3, $elapsed);
        $this->assertLessThan(4.0, $elapsed);

        $this->assertProcessAliveThenKill($result['pid']);
    }

    public function testCallDaemonWithPatternTimesOutAndKeepsProcess(): void
    {
        $started = microtime(true);
        $result = $this->callTool($this->make(BashTool::class), 'bash', [
            'command' => 'sleep 30',
            'pattern' => 'never-match-' . uniqid('', true),
            'daemon' => true,
            'timeout' => 1,
        ]);
        $elapsed = microtime(true) - $started;

        $this->assertTrue($result['daemon'] ?? false);
        $this->assertTrue($result['timeout'] ?? false);
        $this->assertArrayNotHasKey('matched', $result);
        $this->assertArrayHasKey('pid', $result);
        $this->assertGreaterThan(0, $result['pid']);
        $this->assertLessThan(4.0, $elapsed);

        $this->assertProcessAliveThenKill($result['pid']);
    }

    public function testCallDaemonWithPatternCancelledKeepsProcess(): void
    {
        // Counter-style probe: run-level stop flips mid-run — the same OR the poll
        // reads in production when the host cancels or the call is marked.
        $agent = CountingStopAgent::in();
        $tool = $this->make(BashTool::class);
        $arguments = $this->make(ArgumentBinder::class)->bind($tool->schema('bash'), [
            'command' => 'sleep 30',
            'pattern' => 'never-match-' . uniqid('', true),
            'daemon' => true,
        ]);
        $result = $tool->call(
            $agent,
            new ToolCall(id: 'test', name: 'bash', arguments: $arguments),
            $arguments,
        );

        $this->assertTrue($result['daemon'] ?? false);
        $this->assertTrue($result['cancelled'] ?? false);
        $this->assertArrayNotHasKey('matched', $result);
        $this->assertArrayNotHasKey('timeout', $result);
        $this->assertArrayHasKey('pid', $result);
        $this->assertArrayHasKey('elapsed', $result);
        $this->assertGreaterThan(0.0, $result['elapsed']);

        $this->assertProcessAliveThenKill($result['pid']);
    }

    public function testCallDaemonWithPatternReturnsWhenProcessExitsWithoutMatch(): void
    {
        $started = microtime(true);
        $result = $this->callTool($this->make(BashTool::class), 'bash', [
            'command' => 'echo only; exit 0',
            'pattern' => 'never-match-' . uniqid('', true),
            'daemon' => true,
        ]);
        $elapsed = microtime(true) - $started;

        $this->assertTrue($result['daemon'] ?? false);
        $this->assertArrayNotHasKey('matched', $result);
        $this->assertArrayNotHasKey('timeout', $result);
        $this->assertArrayNotHasKey('cancelled', $result);
        $this->assertStringContainsString('only', $result['stdout']);
        $this->assertLessThan(4.0, $elapsed);
    }

    public function testCallDaemonWithInvalidPatternDoesNotMatch(): void
    {
        $result = $this->callTool($this->make(BashTool::class), 'bash', [
            'command' => 'echo ready; sleep 30',
            'pattern' => '(unclosed',
            'daemon' => true,
            'timeout' => 1,
        ]);

        $this->assertTrue($result['daemon'] ?? false);
        $this->assertArrayNotHasKey('matched', $result);
        $this->assertTrue(($result['timeout'] ?? false) || ($result['exited'] ?? false));
        if (isset($result['pid'])) {
            $this->assertProcessAliveThenKill($result['pid']);
        }
    }

    public function testCallDaemonWithPatternSpillsLargeOutput(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('CreateProcess rejects a command line this long.');
        }

        $payload = str_repeat('a', 16 * 1024) . 'READY' . str_repeat('z', 16 * 1024);
        $result = $this->callTool(
            $this->make(BashTool::class),
            'bash',
            [
                'command' => 'printf "%s" "' . $payload . '"; sleep 30',
                'pattern' => 'READY',
                'daemon' => true,
            ],
        );

        $this->assertTrue($result['matched'] ?? false);
        $this->assertArrayNotHasKey('truncated', $result);
        $this->assertStringContainsString('recall: @thread/tool-result/test-stdout.txt', $result['stdout']);
        $this->assertStringContainsString('...[truncated:', $result['stdout']);
        $this->assertStringStartsWith(str_repeat('a', 1024), $result['stdout']);
        $this->assertStringEndsWith(str_repeat('z', 1024), $result['stdout']);

        $this->assertProcessAliveThenKill($result['pid']);
    }

    public function testDaemonPatternMatchesPastCeilingWithoutKeepingTheTail(): void
    {
        $this->container->set(ProcessInterface::class, TinyCeilingProcess::class);
        $result = $this->callTool($this->make(BashTool::class), 'bash', [
            'command' => 'printf "%s" "' . str_repeat('x', 200) . 'READY"; sleep 30',
            'pattern' => 'READY',
            'daemon' => true,
        ]);

        try {
            $this->assertTrue($result['matched'] ?? false);
            $this->assertStringContainsString(Spiller::ceilingExceeded(100), $result['stdout']);
            $this->assertStringNotContainsString('READY', $result['stdout']);
            $this->assertGreaterThan(0, $result['pid'] ?? 0);
        } finally {
            if (($result['pid'] ?? 0) > 0) {
                ProcessTree::terminate((int)$result['pid'], 0.0);
            }
        }
    }

    public function testCallHardCutsWhenInMemoryCeilingHit(): void
    {
        $this->container->set(ProcessInterface::class, TinyCeilingProcess::class);
        $result = $this->callTool(
            $this->make(BashTool::class),
            'bash',
            ['command' => 'printf "%s" "' . str_repeat('x', 250) . '"'],
        );

        $this->assertSame(0, $result['exit']);
        $this->assertStringStartsWith(str_repeat('x', 100), $result['stdout']);
        $this->assertStringContainsString(Spiller::ceilingExceeded(100), $result['stdout']);
        $this->assertArrayNotHasKey('truncated', $result);
    }

    public function testCallDaemonRespectsWorkingDirectory(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/marker.txt', "from-cwd\n");

        $result = $this->callTool($this->make(BashTool::class), 'bash', [
            'command' => 'cat marker.txt; sleep 30',
            'cwd' => $dir,
            'pattern' => 'from-cwd',
            'daemon' => true,
        ]);

        $this->assertTrue($result['matched'] ?? false);
        $this->assertStringContainsString('from-cwd', $result['stdout']);

        $this->assertProcessAliveThenKill($result['pid']);
    }

    public function testCallRejectsRelativeWorkingDirectory(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Working directory must be absolute');

        $this->callTool($this->make(BashTool::class), 'bash', [
            'command' => 'echo hi',
            'cwd' => 'relative/dir',
        ]);
    }

    public function testCallTimesOutCommandThatKeepsStdoutOpen(): void
    {
        $previous = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '-1');

        try {
            $started = microtime(true);
            $result = $this->callTool(
                $this->make(BashTool::class),
                'bash',
                ['command' => 'echo start; sleep 30', 'timeout' => 1],
            );
            $elapsed = microtime(true) - $started;
        } finally {
            ini_set('default_socket_timeout', (string)$previous);
        }

        $this->assertTrue($result['timeout'] ?? false);
        $this->assertStringContainsString('start', $result['stdout']);
        $this->assertLessThan(4.0, $elapsed);
    }

    public function testCallReportsNonZeroExitUnderSwooleHooks(): void
    {
        if (!extension_loaded('swoole') || !defined('SWOOLE_HOOK_ALL')) {
            $this->markTestSkipped('swoole with SWOOLE_HOOK_ALL required');
        }

        $prevHooks = \Swoole\Runtime::getHookFlags();
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        $exitCode = null;
        $error = null;
        try {
            \Swoole\Coroutine\run(function () use (&$exitCode, &$error): void {
                try {
                    $result = $this->callTool($this->make(BashTool::class), 'bash', [
                        'command' => 'exit 7',
                    ]);
                    $exitCode = $result['exit'] ?? null;
                } catch (Throwable $e) {
                    $error = $e;
                }
            });
        } finally {
            \Swoole\Runtime::setHookFlags($prevHooks);
        }

        if ($error !== null) {
            throw $error;
        }
        $this->assertSame(7, $exitCode);
    }

    public function testCallReturnsPartialOutputWhenCancelled(): void
    {
        // Counter-style probe subprocess: flips its stop poll mid-run so partial output is kept.
        $this->container->set(ProcessInterface::class, AbortingProcess::class);
        $result = $this->callTool(
            $this->make(BashTool::class),
            'bash',
            ['command' => 'echo partial; sleep 30'],
        );

        $this->assertTrue($result['cancelled'] ?? false);
        $this->assertSame(130, $result['exit']);
        $this->assertStringContainsString('partial', $result['stdout']);
        $this->assertArrayHasKey('elapsed', $result);
        $this->assertGreaterThan(0.0, $result['elapsed']);

        $keys = array_keys($result);
        $stdoutIndex = array_search('stdout', $keys, true);
        $stderrIndex = array_search('stderr', $keys, true);
        $this->assertIsInt($stdoutIndex);
        $this->assertIsInt($stderrIndex);
        $this->assertLessThan($stdoutIndex, $stderrIndex);
        $this->assertLessThan($stdoutIndex, array_search('cancelled', $keys, true));
        $this->assertLessThan($stdoutIndex, array_search('elapsed', $keys, true));
    }

    public function testEmitsToolResultDelta(): void
    {
        $this->enableEventDispatching();

        /** @var \Switon\Testing\EventDispatcher $dispatcher */
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $dispatcher->clear();

        $this->callTool($this->make(BashTool::class), 'bash', [
            'command' => 'echo delta-bash',
        ]);

        $this->assertNotEmpty($dispatcher->getEventsByClass(ToolResultDelta::class));
    }

    public function testPatternMatchesAfterInMemoryCeilingIsReached(): void
    {
        // 250 bytes output vs a 100-byte ceiling: the marker lands outside the returned buffer.
        $this->container->set(ProcessInterface::class, TinyCeilingProcess::class);
        $result = $this->callTool(
            $this->make(BashTool::class),
            'bash',
            [
                'command' => 'printf "%s" "' . str_repeat('x', 200) . 'NEEDLE_AFTER_CAP"',
                'pattern' => 'NEEDLE_AFTER_CAP',
            ],
        );

        $this->assertTrue($result['matched'] ?? false);
        $this->assertStringNotContainsString('NEEDLE_AFTER_CAP', $result['stdout']);
        $this->assertStringContainsString(Spiller::ceilingExceeded(100), $result['stdout']);
    }

    public function testPatternDoesNotMatchBeforeItIsWritten(): void
    {
        $result = $this->callTool($this->make(BashTool::class), 'bash', [
            'command' => 'echo done',
            'pattern' => 'NEVER_PRESENT',
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertArrayNotHasKey('matched', $result);
    }

    protected function assertProcessAliveThenKill(int $pid): void
    {
        $this->assertTrue(ProcessTree::alive($pid), "expected pid {$pid} to still be alive");
        ProcessTree::terminate($pid, 0.0);
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/switon-bash-tool-' . uniqid('', true);
        mkdir($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }
}

class OneSecondProcess extends Process
{
    public function timeout(): int
    {
        return 1;
    }
}

class TinyCeilingProcess extends Process
{
    public function ceilingBytes(): int
    {
        return 100;
    }
}

/** Stop-poll probe: flips cancelled mid-run (counter-style) — Process path. */
class AbortingProcess extends Process
{
    protected int $aborts = 0;

    protected function stopped(): bool
    {
        return ++$this->aborts > 2;
    }
}

/** Run-stop probe: flips cancelled mid-run (counter-style) — tool-level poll (daemon wait). */
class CountingStopAgent extends ToolTestAgent
{
    protected int $polls = 0;

    public function isCancelled(): bool
    {
        return ++$this->polls > 3;
    }
}
