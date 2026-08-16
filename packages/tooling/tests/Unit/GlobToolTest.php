<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Tooling\Exception;
use Switon\Tooling\Process;
use Switon\Tooling\ProcessInterface;
use Switon\Tooling\Tests\ToolTestCase;
use Switon\Tooling\Tool\GlobTool;

#[CoversClass(GlobTool::class)]
#[CoversClass(Process::class)]
class GlobToolTest extends ToolTestCase
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
                    unlink($path);
                }
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    public function testSchemaListsCoreProperties(): void
    {
        $schema = $this->make(GlobTool::class)->schema('glob');

        $this->assertStringContainsString('relative to target_directory', $schema->definition['description']);
        $this->assertSame(['glob_pattern'], $schema->definition['inputSchema']['required']);
        $this->assertSame(
            'e.g. *.php, **/AGENTS.md, or **/test_*.ts.',
            $schema->definition['inputSchema']['properties']['glob_pattern']['description'],
        );
        $this->assertStringContainsString(
            'relative to this',
            $schema->definition['inputSchema']['properties']['target_directory']['description'],
        );
        $this->assertArrayNotHasKey('default', $schema->definition['inputSchema']['properties']['target_directory']);
        $this->assertSame(500, $schema->definition['inputSchema']['properties']['head_limit']['default']);
        $this->assertSame(1, $schema->definition['inputSchema']['properties']['head_limit']['minimum']);
        $this->assertArrayNotHasKey('maximum', $schema->definition['inputSchema']['properties']['head_limit']);
    }

    public function testCallFindsGlobPatternInDirectory(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/alpha.txt', 'alpha content');
        file_put_contents($dir . '/beta.txt', 'beta content');
        file_put_contents($dir . '/gamma.md', 'gamma content');

        $result = $this->callTool($this->make(GlobTool::class), 'glob', [
            'glob_pattern' => '*.txt',
            'target_directory' => $dir,
        ]);

        $this->assertArrayNotHasKey('exit', $result);
        $this->assertCount(2, $result['paths']);
        $this->assertContains('alpha.txt', $result['paths']);
        $this->assertContains('beta.txt', $result['paths']);
    }

    public function testCallDefaultsTargetDirectoryToWorkspace(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/only-here.txt', 'x');

        $result = $this->callTool(
            $this->make(GlobTool::class),
            'glob',
            ['glob_pattern' => 'only-here.txt'],
            workspace: $dir,
        );

        $this->assertNotEmpty($result['paths']);
        $this->assertContains('only-here.txt', $result['paths']);
    }

    public function testCallWithCustomTargetDirectory(): void
    {
        $baseDir = $this->tempDir();
        $subDir = $baseDir . '/subdir';
        mkdir($subDir);
        file_put_contents($subDir . '/nested.txt', 'nested content');
        file_put_contents($baseDir . '/root.txt', 'root content');

        $result = $this->callTool($this->make(GlobTool::class), 'glob', [
            'glob_pattern' => '**/*.txt',
            'target_directory' => $baseDir,
        ]);

        foreach ($result['paths'] as $path) {
            $this->assertStringNotContainsString($baseDir, $path);
        }
        $this->assertContains('subdir/nested.txt', $result['paths']);
        $this->assertContains('root.txt', $result['paths']);
    }

    public function testCallRelativizesDescendantsAndRootButKeepsOutsidePaths(): void
    {
        $dir = $this->tempDir();
        $rg = $dir . '/rg';
        file_put_contents(
            $rg,
            "#!/bin/sh\nprintf '%s\\n' '/elsewhere/keep-me.txt' " . escapeshellarg($dir) . ' ' . escapeshellarg($dir . '/nested/file.txt') . "\n",
        );
        chmod($rg, 0755);

        $result = $this->callTool($this->make(GlobTool::class, ['rg' => $rg]), 'glob', [
            'glob_pattern' => '*',
            'target_directory' => $dir,
        ]);

        $this->assertSame(['.', '/elsewhere/keep-me.txt', 'nested/file.txt'], $result['paths']);
    }

    public function testCallStripsLeadingSlashWhenTargetIsFilesystemRoot(): void
    {
        $dir = $this->tempDir();
        $rg = $dir . '/rg';
        file_put_contents($rg, "#!/bin/sh\nprintf '%s\\n' '/usr/local/bin/rg' '/'\n");
        chmod($rg, 0755);

        $result = $this->callTool($this->make(GlobTool::class, ['rg' => $rg]), 'glob', [
            'glob_pattern' => '*',
            'target_directory' => '/',
        ]);

        $this->assertSame(['.', 'usr/local/bin/rg'], $result['paths']);
    }

    public function testCallWithNoMatches(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/somefile.txt', 'content');

        $result = $this->callTool($this->make(GlobTool::class), 'glob', [
            'glob_pattern' => '*.nonexistent',
            'target_directory' => $dir,
        ]);

        $this->assertArrayNotHasKey('exit', $result);
        $this->assertSame([], $result['paths']);
    }

    public function testCallFindsNestedAgentsMdViaDoubleStar(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/pkg');
        $agents = $dir . '/pkg/AGENTS.md';
        file_put_contents($agents, '# agents');

        $result = $this->callTool($this->make(GlobTool::class), 'glob', [
            'glob_pattern' => '**/AGENTS.md',
            'target_directory' => $dir,
        ]);

        $this->assertSame(['pkg/AGENTS.md'], $result['paths']);
    }

    public function testCallWithSmallResultIsNotTruncated(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/single.txt', 'content');

        $result = $this->callTool($this->make(GlobTool::class), 'glob', [
            'glob_pattern' => '*.txt',
            'target_directory' => $dir,
        ]);

        $this->assertCount(1, $result['paths']);
        $this->assertArrayNotHasKey('truncated', $result);
    }

    public function testCallTruncatesLargeResultSetsWithoutTimingOut(): void
    {
        $dir = $this->tempDir();
        for ($index = 0; $index < 600; $index += 1) {
            file_put_contents($dir . '/file-' . $index . '.txt', 'x');
        }

        $startedAt = microtime(true);
        $result = $this->callTool($this->make(GlobTool::class), 'glob', [
            'glob_pattern' => '*.txt',
            'target_directory' => $dir,
        ]);
        $elapsed = microtime(true) - $startedAt;

        // Relative basenames stay under default spill budget; head_limit still caps at 500.
        $this->assertIsArray($result);
        $this->assertTrue($result['truncated']);
        $this->assertCount(500, $result['paths']);
        $this->assertDoesNotMatchRegularExpression('#^/#', $result['paths'][0]);
        $this->assertLessThan(5.0, $elapsed);
    }

    public function testCallHonorsHeadLimitWithoutExtraCap(): void
    {
        $dir = $this->tempDir();
        for ($index = 0; $index < 8; $index += 1) {
            file_put_contents($dir . '/file-' . $index . '.txt', 'x');
        }

        $result = $this->callTool($this->make(GlobTool::class), 'glob', [
            'glob_pattern' => '*.txt',
            'target_directory' => $dir,
            'head_limit' => 3,
        ]);

        $this->assertTrue(($result['truncated'] ?? false) === true);
        $this->assertCount(3, $result['paths']);
    }

    public function testCallSpillsWhenSerializedResultExceedsBudget(): void
    {
        $dir = $this->tempDir();
        $name = str_repeat('p', 80);
        for ($index = 0; $index < 40; $index += 1) {
            file_put_contents($dir . '/' . $name . $index . '.txt', 'x');
        }
        $spillDir = $this->tempDir();
        mkdir($spillDir . '/tool-result');
        $result = $this->callTool($this->make(GlobTool::class, ['budget' => 1]), 'glob', [
            'glob_pattern' => '*.txt',
            'target_directory' => $dir,
            'head_limit' => 40,
        ], datadir: $spillDir, callId: 'glob');

        $this->assertIsString($result);
        $this->assertStringContainsString('recall: @thread/tool-result/glob.txt', $result);
        $stored = json_decode((string)file_get_contents($spillDir . '/tool-result/glob.txt'), true);
        $this->assertCount(40, $stored['paths']);
    }

    public function testRipgrepExitAboveOneRaises(): void
    {
        $binDir = $this->tempDir();
        $rg = $binDir . '/rg';
        file_put_contents($rg, "#!/bin/sh\necho boom >&2\nexit 2\n");
        chmod($rg, 0755);

        $previous = getenv('PATH') ?: '';
        putenv('PATH=' . $binDir . PATH_SEPARATOR . $previous);

        try {
            $this->expectException(Exception::class);
            $this->expectExceptionMessage('ripgrep failed');
            $this->callTool($this->make(GlobTool::class), 'glob', [
                'glob_pattern' => '*.txt',
                'target_directory' => $this->tempDir(),
            ]);
        } finally {
            putenv('PATH=' . $previous);
        }
    }

    public function testIncludesStderrWhenPresent(): void
    {
        $binDir = $this->tempDir();
        $dir = $this->tempDir();
        $rg = $binDir . '/rg';
        file_put_contents($rg, "#!/bin/sh\necho warn >&2\necho " . escapeshellarg($dir . '/fake.txt') . "\nexit 0\n");
        chmod($rg, 0755);

        $previous = getenv('PATH') ?: '';
        putenv('PATH=' . $binDir . PATH_SEPARATOR . $previous);

        try {
            $result = $this->callTool($this->make(GlobTool::class), 'glob', [
                'glob_pattern' => '*.txt',
                'target_directory' => $dir,
            ]);
        } finally {
            putenv('PATH=' . $previous);
        }

        $this->assertArrayNotHasKey('exit', $result);
        $this->assertContains('fake.txt', $result['paths']);
        $this->assertStringContainsString('warn', (string)($result['stderr'] ?? ''));
    }

    public function testTimesOutLongRunningFakeRg(): void
    {
        $binDir = $this->tempDir();
        $dir = $this->tempDir();
        $rg = $binDir . '/rg';
        file_put_contents($rg, "#!/bin/sh\necho " . escapeshellarg($dir . '/partial.txt') . "\nsleep 30\n");
        chmod($rg, 0755);

        $previous = getenv('PATH') ?: '';
        putenv('PATH=' . $binDir . PATH_SEPARATOR . $previous);
        $previousSocket = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '-1');

        try {
            $this->container->set(ProcessInterface::class, OneSecondProcess::class);
            $result = $this->callTool(
                $this->make(GlobTool::class),
                'glob',
                ['glob_pattern' => '*.txt', 'target_directory' => $dir],
            );
        } finally {
            putenv('PATH=' . $previous);
            ini_set('default_socket_timeout', (string)$previousSocket);
        }

        $this->assertTrue($result['timeout'] ?? false);
        $this->assertContains('partial.txt', $result['paths']);
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/switon-agent-glob-' . uniqid('', true);
        mkdir($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }
}
