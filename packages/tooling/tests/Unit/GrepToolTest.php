<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Event\ToolResultDelta;
use Switon\Eventing\EventDispatcherInterface;
use Switon\Tooling\ArgumentBinder;
use Switon\Tooling\Exception;
use Switon\Tooling\Exception\ArgumentBindException;
use Switon\Tooling\Process;
use Switon\Tooling\ProcessInterface;
use Switon\Tooling\Spiller;
use Switon\Tooling\Tests\ToolTestCase;
use Switon\Tooling\Tool\GrepTool;

#[CoversClass(GrepTool::class)]
class GrepToolTest extends ToolTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (in_array($this->name(), [
            'testSchemaListsCoreProperties',
            'testRejectsNonTypedOptionValues',
            'testOpenProcessFailureRaises',
        ], true) || $this->executableOnPath('rg')) {
            return;
        }
        $this->markTestSkipped('rg is not on PATH');
    }

    public function testSchemaListsCoreProperties(): void
    {
        $schema = $this->make(GrepTool::class)->schema('grep');

        $this->assertSame('Search file contents with ripgrep.', $schema->definition['description']);
        $this->assertSame(['pattern'], $schema->definition['inputSchema']['required']);
        $this->assertStringContainsString('workspace', $schema->definition['inputSchema']['properties']['path']['description']);
        foreach (['pattern', 'path', 'glob', 'output_mode', '-B', '-A', '-C', 'head_limit', 'offset', 'multiline', 'literal', 'type', '-i'] as $key) {
            $this->assertArrayHasKey($key, $schema->definition['inputSchema']['properties']);
        }
        $this->assertStringContainsString('Include only matching paths', $schema->definition['inputSchema']['properties']['glob']['description']);
        $this->assertStringContainsString('span lines', $schema->definition['inputSchema']['properties']['multiline']['description']);
        $this->assertStringContainsString('literal', $schema->definition['inputSchema']['properties']['literal']['description']);
        $this->assertSame(0, $schema->definition['inputSchema']['properties']['-B']['minimum']);
        $this->assertSame(0, $schema->definition['inputSchema']['properties']['-A']['minimum']);
        $this->assertSame(0, $schema->definition['inputSchema']['properties']['-C']['minimum']);
        $this->assertSame(1, $schema->definition['inputSchema']['properties']['head_limit']['minimum']);
        $this->assertSame(0, $schema->definition['inputSchema']['properties']['offset']['minimum']);
        $this->assertFalse($schema->definition['inputSchema']['properties']['multiline']['default']);
        $this->assertFalse($schema->definition['inputSchema']['properties']['literal']['default']);
        $this->assertFalse($schema->definition['inputSchema']['properties']['-i']['default']);
        $this->assertSame('content', $schema->definition['inputSchema']['properties']['output_mode']['default']);
    }

    public function testCallFindsPatternInDirectory(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/sample.txt', "alpha\nbeta target line\ngamma\n");

        $result = $this->callTool($this->make(GrepTool::class), 'grep', [
            'pattern' => 'target',
            'path' => $dir,
        ]);

        $this->assertTrue($result['matches']);
        $this->assertStringContainsString('target line', $result['stdout']);
    }

    public function testHeadLimitCapsTotalMatchesAcrossFiles(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/a.txt', "target one\ntarget two\n");
        file_put_contents($dir . '/b.txt', "target three\ntarget four\n");

        $result = $this->callTool($this->make(GrepTool::class), 'grep', [
            'pattern' => 'target',
            'path' => $dir,
            'head_limit' => 2,
        ]);

        $this->assertSame(2, substr_count($result['stdout'], 'target'));
    }

    public function testHeadLimitStopsBeforeLaterOutput(): void
    {
        $rg = $this->tempDir() . '/rg';
        file_put_contents($rg, "#!/bin/sh\nprintf 'a:1:match one\\n'\nsleep 30\n");
        chmod($rg, 0755);

        $started = microtime(true);
        $result = $this->callTool($this->make(GrepTool::class, ['rg' => $rg, 'timeout' => 5]), 'grep', [
            'pattern' => 'match',
            'path' => $this->tempDir(),
            'head_limit' => 1,
        ]);
        $elapsed = microtime(true) - $started;

        $this->assertTrue($result['matches']);
        $this->assertStringContainsString('match one', $result['stdout']);
        $this->assertLessThan(4.0, $elapsed);
    }

    public function testHeadLimitWithContextWaitsForTheNextMatchThenDropsIt(): void
    {
        $rg = $this->tempDir() . '/rg';
        file_put_contents($rg, "#!/bin/sh\nprintf 'a:1:match one\\n'\nsleep 0.3\nprintf 'a-2-after\\n'\nprintf 'a:3:match two\\n'\nsleep 30\n");
        chmod($rg, 0755);

        $started = microtime(true);
        $result = $this->callTool($this->make(GrepTool::class, ['rg' => $rg, 'timeout' => 5]), 'grep', [
            'pattern' => 'match',
            'path' => $this->tempDir(),
            '-A' => 1,
            'head_limit' => 1,
        ]);
        $elapsed = microtime(true) - $started;

        $this->assertTrue($result['matches']);
        $this->assertStringContainsString('match one', $result['stdout']);
        $this->assertStringContainsString('after', $result['stdout']);
        $this->assertStringNotContainsString('match two', $result['stdout']);
        $this->assertGreaterThanOrEqual(0.2, $elapsed);
        $this->assertLessThan(4.0, $elapsed);
    }

    public function testOffsetSkipsMatchesAcrossFiles(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/a.txt', "target one\ntarget two\n");
        file_put_contents($dir . '/b.txt', "target three\ntarget four\n");

        $result = $this->callTool($this->make(GrepTool::class), 'grep', [
            'pattern' => 'target',
            'path' => $dir,
            'offset' => 2,
        ]);

        $this->assertSame(2, substr_count($result['stdout'], 'target'));
    }

    public function testOffsetPastEndReturnsEmptyStdout(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/a.txt', "target one\n");

        $result = $this->callTool($this->make(GrepTool::class), 'grep', [
            'pattern' => 'target',
            'path' => $dir,
            'offset' => 99,
        ]);

        $this->assertSame('', $result['stdout']);
    }

    public function testCallIncludesContextLines(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/ctx.txt', "before\nmatch here\nafter\n");

        $result = $this->callTool($this->make(GrepTool::class), 'grep', [
            'pattern' => 'match',
            'path' => $dir . '/ctx.txt',
            '-C' => 1,
        ]);

        $this->assertTrue($result['matches']);
        $this->assertStringContainsString('before', $result['stdout']);
        $this->assertStringContainsString('match here', $result['stdout']);
        $this->assertStringContainsString('after', $result['stdout']);
    }

    public function testBeforeAndAfterContextWithoutC(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/ctx.txt', "b0\nb1\nmatch here\na1\na2\n");

        $result = $this->callTool($this->make(GrepTool::class), 'grep', [
            'pattern' => 'match',
            'path' => $dir . '/ctx.txt',
            '-B' => 1,
            '-A' => 1,
        ]);

        $this->assertStringContainsString('b1', $result['stdout']);
        $this->assertStringContainsString('a1', $result['stdout']);
        $this->assertStringNotContainsString('b0', $result['stdout']);
        $this->assertStringNotContainsString('a2', $result['stdout']);
    }

    public function testFilesWithMatchesMode(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/hit.txt', "needle\n");
        file_put_contents($dir . '/miss.txt', "other\n");

        $result = $this->callTool($this->make(GrepTool::class), 'grep', [
            'pattern' => 'needle',
            'path' => $dir,
            'output_mode' => 'files_with_matches',
        ]);

        $this->assertTrue($result['matches']);
        $this->assertStringContainsString('hit.txt', $result['stdout']);
        $this->assertStringNotContainsString('miss.txt', $result['stdout']);
    }

    public function testCountModeWithOffsetAndHeadLimit(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/a.txt', "x\nx\n");
        file_put_contents($dir . '/b.txt', "x\n");
        file_put_contents($dir . '/c.txt', "x\nx\nx\n");

        $result = $this->callTool($this->make(GrepTool::class), 'grep', [
            'pattern' => 'x',
            'path' => $dir,
            'output_mode' => 'count',
            'offset' => 1,
            'head_limit' => 1,
        ]);

        $lines = array_values(array_filter(explode("\n", trim($result['stdout']))));
        $this->assertCount(1, $lines);
    }

    public function testCountModeReportsFullCountPerFile(): void
    {
        $dir = $this->tempDir();
        // Well past the default `maxCount`; the cap is a line-emission bound, not a count cap.
        file_put_contents($dir . '/many.txt', str_repeat("x\n", 400));

        $result = $this->callTool($this->make(GrepTool::class), 'grep', [
            'pattern' => 'x',
            'path' => $dir . '/many.txt',
            'output_mode' => 'count',
        ]);

        $this->assertSame(400, (int) trim($result['stdout']));
    }

    public function testGlobFiltersFiles(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/keep.txt', "token-keep\n");
        file_put_contents($dir . '/skip.md', "token-keep\n");

        $result = $this->callTool($this->make(GrepTool::class), 'grep', [
            'pattern' => 'token-keep',
            'path' => $dir,
            'glob' => '*.txt',
            'output_mode' => 'files_with_matches',
        ]);

        $this->assertStringContainsString('keep.txt', $result['stdout']);
        $this->assertStringNotContainsString('skip.md', $result['stdout']);
    }

    public function testCaseInsensitiveAndTypeAndMultiline(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/Sample.php', "<?php\nAbC\n");
        file_put_contents($dir . '/other.txt', "AbC\n");

        $result = $this->callTool($this->make(GrepTool::class), 'grep', [
            'pattern' => 'abc',
            'path' => $dir,
            '-i' => true,
            'type' => 'php',
            'multiline' => true,
        ]);

        $this->assertTrue($result['matches']);
        $this->assertStringContainsString('Sample.php', $result['stdout']);
        $this->assertStringNotContainsString('other.txt', $result['stdout']);
    }

    public function testLiteralTreatsMetacharactersAsPlainText(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/a.txt', "price is $5.00\n");
        file_put_contents($dir . '/b.txt', "price is 500\n");

        $result = $this->callTool($this->make(GrepTool::class), 'grep', [
            'pattern' => '$5.00',
            'path' => $dir,
            'literal' => true,
        ]);

        $this->assertTrue($result['matches']);
        $this->assertStringContainsString('a.txt', $result['stdout']);
        $this->assertStringNotContainsString('b.txt', $result['stdout']);
    }

    public function testRejectsNonTypedOptionValues(): void
    {
        try {
            (new ArgumentBinder())->bind($this->make(GrepTool::class)->schema('grep'), [
                'pattern' => 'hit',
                // Digit strings coerce to integer; use a non-digit to assert rejection.
                'head_limit' => 'two',
                'type' => 1,
                'multiline' => 'yes',
                '-i' => 'yes',
            ]);
            $this->fail('expected ArgumentBindException');
        } catch (ArgumentBindException $e) {
            $this->assertSame('head_limit type invalid', $e->errors['head_limit']);
            $this->assertSame('type type invalid', $e->errors['type']);
            $this->assertSame('multiline type invalid', $e->errors['multiline']);
            $this->assertSame('-i type invalid', $e->errors['-i']);
        }
    }

    public function testCoercesDigitStringHeadLimit(): void
    {
        $bound = (new ArgumentBinder())->bind($this->make(GrepTool::class)->schema('grep'), [
            'pattern' => 'hit',
            'head_limit' => '2',
        ]);

        $this->assertSame(2, $bound['head_limit']);
    }

    public function testTruncatesOversizedStdout(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/big.txt', str_repeat("match-line\n", 200));

        $this->container->set(ProcessInterface::class, TinyCeilingGrepProcess::class);
        $result = $this->callTool(
            $this->make(GrepTool::class),
            'grep',
            [
                'pattern' => 'match-line',
                'path' => $dir . '/big.txt',
            ],
        );

        $this->assertStringContainsString(Spiller::ceilingExceeded(40), $result['stdout']);
    }

    public function testEmitsToolResultDelta(): void
    {
        $this->enableEventDispatching();
        $dir = $this->tempDir();
        file_put_contents($dir . '/a.txt', "delta-token\n");

        /** @var \Switon\Testing\EventDispatcher $dispatcher */
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $dispatcher->clear();

        $this->callTool($this->make(GrepTool::class), 'grep', [
            'pattern' => 'delta-token',
            'path' => $dir,
        ]);

        $this->assertNotEmpty($dispatcher->getEventsByClass(ToolResultDelta::class));
    }

    public function testTimesOutLongRunningFakeRg(): void
    {
        $binDir = $this->tempDir();
        $rg = $binDir . '/rg';
        file_put_contents($rg, "#!/bin/sh\necho partial\nsleep 30\n");
        chmod($rg, 0755);

        $previous = getenv('PATH') ?: '';
        putenv('PATH=' . $binDir . PATH_SEPARATOR . $previous);
        $previousSocket = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '-1');

        try {
            $result = $this->callTool(
                $this->make(GrepTool::class, ['timeout' => 1]),
                'grep',
                ['pattern' => 'x', 'path' => $this->tempDir()],
            );
        } finally {
            putenv('PATH=' . $previous);
            ini_set('default_socket_timeout', (string)$previousSocket);
        }

        $this->assertTrue($result['timeout'] ?? false);
        $this->assertStringContainsString('partial', $result['stdout']);
    }

    public function testCancelsLongRunningFakeRg(): void
    {
        $binDir = $this->tempDir();
        $rg = $binDir . '/rg';
        file_put_contents($rg, "#!/bin/sh\necho partial\nsleep 30\n");
        chmod($rg, 0755);

        $previous = getenv('PATH') ?: '';
        putenv('PATH=' . $binDir . PATH_SEPARATOR . $previous);

        $this->container->set(ProcessInterface::class, AbortingProcess::class);
        try {
            // Counter-style probe subprocess: flips its stop poll mid-run so partial output is kept.
            $result = $this->callTool(
                $this->make(GrepTool::class),
                'grep',
                ['pattern' => 'x', 'path' => $this->tempDir()],
            );
        } finally {
            putenv('PATH=' . $previous);
        }

        $this->assertTrue($result['cancelled'] ?? false);
        $this->assertSame(130, $result['exit']);
        $this->assertArrayHasKey('elapsed', $result);
        $this->assertStringContainsString('partial', $result['stdout']);
    }

    public function testOpenProcessFailureRaises(): void
    {
        $this->container->set(ProcessInterface::class, FailingStartProcess::class);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to start process');

        $this->callTool(
            $this->make(GrepTool::class),
            'grep',
            ['pattern' => 'x', 'path' => $this->tempDir()],
        );
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/switon-agent-grep-' . uniqid('', true);
        mkdir($dir);

        return $dir;
    }
}

class TinyCeilingGrepProcess extends Process
{
    public function ceilingBytes(): int
    {
        return 40;
    }
}

class FailingStartProcess extends Process
{
    public function start(array $command, string $cwd, int $timeout): static
    {
        unset($command, $cwd, $timeout);
        Exception::raise('Failed to start process "{command}".', ['command' => 'rg']);
    }
}
