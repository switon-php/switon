<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Lint\LinterInterface;
use Switon\Tooling\Tests\ToolTestCase;
use Switon\Tooling\Tool\LintTool;
use Switon\Tooling\Tool\WriteTool;

#[CoversClass(LintTool::class)]
class LintToolTest extends ToolTestCase
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

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/switon-tooling-lint-tool-' . uniqid('', true);
        mkdir($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    public function testSchemaDefaultsDeepToFalse(): void
    {
        $schema = $this->make(LintTool::class)->schema('lint');

        $this->assertFalse($schema->definition['inputSchema']['properties']['deep']['default']);
        $this->assertTrue($this->make(LintTool::class)->isReadonly('lint', ['path' => '/tmp/a.php']));
    }

    public function testLintToolReturnsReport(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/ok.php';
        file_put_contents($file, "<?php\n\nreturn 1;\n");

        $result = $this->callTool($this->make(LintTool::class), 'lint', ['path' => $file]);

        $this->assertSame([['name' => 'php -l', 'ok' => true]], $result['tools']);
        $this->assertArrayNotHasKey('path', $result);
    }

    public function testWriteAttachesLintForPhp(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/new.php';

        $result = $this->callTool($this->make(WriteTool::class), 'write', [
            'path' => $file,
            'content' => "<?php\n\nreturn 1;\n",
        ]);

        $this->assertArrayNotHasKey('path', $result);
        $this->assertArrayHasKey('lint', $result);
        $this->assertSame([['name' => 'php -l', 'ok' => true]], $result['lint']['tools']);
    }

    public function testLintToolSpillsLargeDiagnostics(): void
    {
        $diagnostics = [];
        for ($i = 0; $i < 40; $i++) {
            $diagnostics[] = [
                'line' => $i + 1,
                'severity' => 'error',
                'message' => str_repeat('m', 80),
            ];
        }

        $linter = new class ($diagnostics) implements LinterInterface {
            /** @param list<array{line: int, severity: string, message: string}> $diagnostics */
            public function __construct(protected array $diagnostics)
            {
            }

            public function supports(string $path): bool
            {
                return true;
            }

            public function lint(string $path, callable $abort, bool $deep = false): array
            {
                return [
                    'path' => $path,
                    'tools' => [['name' => 'stub', 'ok' => false]],
                    'diagnostics' => $this->diagnostics,
                ];
            }

            public function attach(array $payload, string|array $paths, callable $abort): array
            {
                return $payload;
            }
        };
        $spillDir = $this->tempDir();
        mkdir($spillDir . '/tool-result');

        $result = $this->callTool(
            $this->make(LintTool::class, ['linter' => $linter, 'budget' => 1]),
            'lint',
            [
                'path' => '/tmp/stub.php',
            ],
            datadir: $spillDir,
            callId: 'lint',
        );

        $this->assertArrayNotHasKey('path', $result);
        $this->assertSame([['name' => 'stub', 'ok' => false]], $result['tools']);
        $this->assertIsString($result['diagnostics']);
        $this->assertStringContainsString('recall: @thread/tool-result/lint-diagnostics.txt', $result['diagnostics']);
        $stored = json_decode((string)file_get_contents($spillDir . '/tool-result/lint-diagnostics.txt'), true);
        $this->assertCount(40, $stored);
    }

    public function testLintKeepsDiagnosticsThatCannotBeEncoded(): void
    {
        $linter = new class () implements LinterInterface {
            public function supports(string $path): bool
            {
                return true;
            }

            public function lint(string $path, callable $abort, bool $deep = false): array
            {
                return [
                    'path' => $path,
                    'tools' => [],
                    'diagnostics' => [["\xFF"]],
                ];
            }

            public function attach(array $payload, string|array $paths, callable $abort): array
            {
                return $payload;
            }
        };

        $result = $this->callTool($this->make(LintTool::class, ['linter' => $linter]), 'lint', [
            'path' => '/tmp/stub.php',
        ]);

        $this->assertSame([["\xFF"]], $result['diagnostics']);
    }
}
