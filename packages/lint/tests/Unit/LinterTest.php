<?php

declare(strict_types=1);

namespace Switon\Lint\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Switon\Lint\Exception;
use Switon\Lint\Linter;
use Switon\Lint\Tests\TestCase;

#[CoversClass(Linter::class)]
class LinterTest extends TestCase
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

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/switon-lint-' . uniqid('', true);
        mkdir($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    protected function removeTree(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $this->removeTree($path . DIRECTORY_SEPARATOR . $name);
        }
        @rmdir($path);
    }

    public function testLintReportsCleanPhpFile(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/ok.php';
        file_put_contents($file, "<?php\n\nreturn 1;\n");

        $result = $this->make(Linter::class)->lint($file, static fn (): bool => false);

        $this->assertSame($file, $result['path']);
        $this->assertSame([], $result['diagnostics']);
        $this->assertSame([['name' => 'php -l', 'ok' => true]], $result['tools']);
    }

    public function testLintReportsPhpSyntaxError(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/bad.php';
        file_put_contents($file, "<?php\n\necho (\n");

        $result = $this->make(Linter::class)->lint($file, static fn (): bool => false);

        $this->assertNotEmpty($result['diagnostics']);
        $this->assertSame('error', $result['diagnostics'][0]['severity']);
        $this->assertGreaterThanOrEqual(1, $result['diagnostics'][0]['line']);
        $this->assertSame([['name' => 'php -l', 'ok' => false]], $result['tools']);
    }

    public function testLintAcceptsUppercasePhpExtension(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/Ok.PHP';
        file_put_contents($file, "<?php\n\nreturn 1;\n");

        $lint = $this->make(Linter::class);
        $this->assertTrue($lint->supports($file));
        $this->assertSame(
            [['name' => 'php -l', 'ok' => true]],
            $lint->lint($file, static fn (): bool => false)['tools'],
        );
    }

    public function testSupports(): void
    {
        $dir = $this->tempDir();
        $php = $dir . '/a.php';
        $js = $dir . '/a.js';
        $mjs = $dir . '/a.mjs';
        $txt = $dir . '/a.txt';
        file_put_contents($php, "<?php\n");
        file_put_contents($js, "const a = 1;\n");
        file_put_contents($mjs, "export const a = 1;\n");
        file_put_contents($txt, 'x');

        $lint = $this->make(Linter::class);
        $this->assertTrue($lint->supports($php));
        $this->assertTrue($lint->supports($js));
        $this->assertTrue($lint->supports($mjs));
        $this->assertFalse($lint->supports($txt));
        $this->assertFalse($lint->supports('/missing.php'));
        $this->assertFalse($lint->supports(''));
    }

    public function testLintReportsCleanJsFile(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/ok.js';
        file_put_contents($file, "const a = 1;\n");

        $result = $this->make(Linter::class)->lint($file, static fn (): bool => false);

        $this->assertSame($file, $result['path']);
        $this->assertSame([], $result['diagnostics']);
        $this->assertSame([['name' => 'node --check', 'ok' => true]], $result['tools']);
    }

    public function testLintReportsJsSyntaxError(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/bad.js';
        file_put_contents($file, "const a = (\n");

        $result = $this->make(Linter::class)->lint($file, static fn (): bool => false);

        $this->assertNotEmpty($result['diagnostics']);
        $this->assertSame('error', $result['diagnostics'][0]['severity']);
        $this->assertSame([['name' => 'node --check', 'ok' => false]], $result['tools']);
    }

    public function testLintReportsCleanGoFile(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/ok.go';
        file_put_contents($file, "package p\n\nfunc F() {}\n");

        $result = $this->make(Linter::class)->lint($file, static fn (): bool => false);

        $this->assertSame($file, $result['path']);
        $this->assertSame([], $result['diagnostics']);
        $this->assertSame([['name' => 'gofmt', 'ok' => true]], $result['tools']);
    }

    public function testLintReportsGoSyntaxError(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/bad.go';
        file_put_contents($file, "package main\n\nfunc main() {\n\tx :=\n}\n");

        $result = $this->make(Linter::class)->lint($file, static fn (): bool => false);

        $this->assertNotEmpty($result['diagnostics']);
        $this->assertSame([['name' => 'gofmt', 'ok' => false]], $result['tools']);
    }

    public function testSupportsGoAndAttach(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/ok.go';
        file_put_contents($file, "package p\n\nfunc F() {}\n");

        $lint = $this->make(Linter::class);
        $this->assertTrue($lint->supports($file));
        $out = $lint->attach(['path' => $file], $file, static fn (): bool => false);
        $this->assertSame([['name' => 'gofmt', 'ok' => true]], $out['lint']['tools']);
    }

    public function testLintReportsCleanShellFile(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/ok.sh';
        file_put_contents($file, "#!/bin/bash\necho hi\n");

        $result = $this->make(Linter::class)->lint($file, static fn (): bool => false);

        $this->assertSame($file, $result['path']);
        $this->assertSame([], $result['diagnostics'], json_encode($result['diagnostics'], JSON_THROW_ON_ERROR));
        $this->assertSame([['name' => 'bash -n', 'ok' => true]], $result['tools']);
    }

    public function testLintReportsShellSyntaxError(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/bad.sh';
        file_put_contents($file, "#!/bin/bash\nif true\nfi\n");

        $result = $this->make(Linter::class)->lint($file, static fn (): bool => false);

        $this->assertNotEmpty($result['diagnostics']);
        $this->assertSame([['name' => 'bash -n', 'ok' => false]], $result['tools']);
    }

    public function testLintReportsCleanPythonFile(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/ok.py';
        file_put_contents($file, "x = 1\n");

        $result = $this->make(Linter::class)->lint($file, static fn (): bool => false);

        $this->assertSame($file, $result['path']);
        $this->assertSame([], $result['diagnostics']);
        $this->assertSame([['name' => 'python', 'ok' => true]], $result['tools']);
    }

    public function testLintReportsPythonSyntaxError(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/bad.py';
        file_put_contents($file, "def f(\n");

        $result = $this->make(Linter::class)->lint($file, static fn (): bool => false);

        $this->assertNotEmpty($result['diagnostics']);
        $this->assertSame([['name' => 'python', 'ok' => false]], $result['tools']);
    }

    public function testLintReportsCleanJsonFile(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/ok.json';
        file_put_contents($file, "{\"a\": 1}\n");

        $result = $this->make(Linter::class)->lint($file, static fn (): bool => false);

        $this->assertSame($file, $result['path']);
        $this->assertSame([], $result['diagnostics']);
        $this->assertSame([['name' => 'json', 'ok' => true]], $result['tools']);
    }

    public function testLintReportsJsonSyntaxError(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/bad.json';
        file_put_contents($file, "{\"a\": 1,}\n");

        $result = $this->make(Linter::class)->lint($file, static fn (): bool => false);

        $this->assertNotEmpty($result['diagnostics']);
        $this->assertSame([['name' => 'json', 'ok' => false]], $result['tools']);
    }

    public function testLintReportsCleanXmlFile(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/ok.xml';
        file_put_contents($file, "<root/>\n");

        $result = $this->make(Linter::class)->lint($file, static fn (): bool => false);

        $this->assertSame([['name' => 'xml', 'ok' => true]], $result['tools']);
        $this->assertSame([], $result['diagnostics']);
    }

    public function testLintReportsXmlSyntaxError(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/bad.xml';
        file_put_contents($file, "<a>\n");

        $result = $this->make(Linter::class)->lint($file, static fn (): bool => false);

        $this->assertSame([['name' => 'xml', 'ok' => false]], $result['tools']);
        $this->assertNotEmpty($result['diagnostics']);
    }

    public function testLintReportsCleanRubyFile(): void
    {
        if ($this->binaryMissing('ruby')) {
            $this->markTestSkipped('ruby not on PATH');
        }
        $dir = $this->tempDir();
        $file = $dir . '/ok.rb';
        file_put_contents($file, "x = 1\n");

        $result = $this->make(Linter::class)->lint($file, static fn (): bool => false);

        $this->assertSame([['name' => 'ruby -c', 'ok' => true]], $result['tools']);
    }

    public function testLintReportsRubySyntaxError(): void
    {
        if ($this->binaryMissing('ruby')) {
            $this->markTestSkipped('ruby not on PATH');
        }
        $dir = $this->tempDir();
        $file = $dir . '/bad.rb';
        file_put_contents($file, "def f\n");

        $result = $this->make(Linter::class)->lint($file, static fn (): bool => false);

        $this->assertSame([['name' => 'ruby -c', 'ok' => false]], $result['tools']);
        $this->assertNotEmpty($result['diagnostics']);
    }

    public function testLintReportsCleanLuaFile(): void
    {
        if ($this->binaryMissing('luac')) {
            $this->markTestSkipped('luac not on PATH');
        }
        $dir = $this->tempDir();
        $file = $dir . '/ok.lua';
        file_put_contents($file, "x = 1\n");

        $result = $this->make(Linter::class)->lint($file, static fn (): bool => false);

        $this->assertSame([['name' => 'luac -p', 'ok' => true]], $result['tools']);
    }

    public function testLintReportsLuaSyntaxError(): void
    {
        if ($this->binaryMissing('luac')) {
            $this->markTestSkipped('luac not on PATH');
        }
        $dir = $this->tempDir();
        $file = $dir . '/bad.lua';
        file_put_contents($file, "function f(\n");

        $result = $this->make(Linter::class)->lint($file, static fn (): bool => false);

        $this->assertSame([['name' => 'luac -p', 'ok' => false]], $result['tools']);
        $this->assertNotEmpty($result['diagnostics']);
    }

    protected function binaryMissing(string $name): bool
    {
        $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));
        foreach ($paths as $dir) {
            if ($dir !== '' && is_file($dir . DIRECTORY_SEPARATOR . $name)) {
                return false;
            }
        }

        return true;
    }

    public function testLintDefaultIsSyntaxOnlyEvenWithPhpstanPresent(): void
    {
        $dir = $this->tempDir();
        $binDir = $dir . '/vendor/bin';
        mkdir($binDir, 0777, true);
        file_put_contents($binDir . '/phpstan', "#!/bin/sh\nexit 0\n");
        chmod($binDir . '/phpstan', 0755);
        $file = $dir . '/ok.php';
        file_put_contents($file, "<?php\n\nreturn 1;\n");

        $result = $this->make(Linter::class)->lint($file, static fn (): bool => false);

        $this->assertSame([['name' => 'php -l', 'ok' => true]], $result['tools']);
    }

    public function testLintRejectsUnknownExtension(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/x.unknown';
        file_put_contents($file, 'x');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No lint driver');

        $this->make(Linter::class)->lint($file, static fn (): bool => false);
    }

    public function testLintRejectsMissingFile(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File not found');

        $this->make(Linter::class)->lint('/no/such/file.php', static fn (): bool => false);
    }

    public function testLintRejectsExtensionlessFile(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/Makefile';
        file_put_contents($file, 'x');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No file extension');

        $this->make(Linter::class)->lint($file, static fn (): bool => false);
    }

    public function testAttachAddsLintKeyForPhp(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/ok.php';
        file_put_contents($file, "<?php\n\nreturn 1;\n");

        $out = $this->make(Linter::class)->attach(
            ['path' => $file, 'replacements' => 1],
            $file,
            static fn (): bool => false,
        );

        $this->assertSame(1, $out['replacements']);
        $this->assertArrayHasKey('lint', $out);
        $this->assertSame([['name' => 'php -l', 'ok' => true]], $out['lint']['tools']);
    }

    public function testAttachSkipsUnsupportedExtension(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/note.txt';
        file_put_contents($file, "x\n");

        $out = $this->make(Linter::class)->attach(
            ['path' => $file],
            $file,
            static fn (): bool => false,
        );

        $this->assertSame(['path' => $file], $out);
    }

    public function testAttachMultiplePathsReturnsLintList(): void
    {
        $dir = $this->tempDir();
        $a = $dir . '/a.php';
        $b = $dir . '/b.php';
        file_put_contents($a, "<?php\n\nreturn 1;\n");
        file_put_contents($b, "<?php\n\nreturn 2;\n");

        $out = $this->make(Linter::class)->attach(
            ['files' => [$a, $b]],
            [$a, $b, $a],
            static fn (): bool => false,
        );

        $this->assertIsArray($out['lint']);
        $this->assertCount(2, $out['lint']);
        $this->assertSame([['name' => 'php -l', 'ok' => true]], $out['lint'][0]['tools']);
        $this->assertSame([['name' => 'php -l', 'ok' => true]], $out['lint'][1]['tools']);
    }

    public function testAttachDisabledIsNoOp(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/ok.php';
        file_put_contents($file, "<?php\n\nreturn 1;\n");

        $lint = $this->make(Linter::class, ['attachEnabled' => false]);
        $payload = ['path' => $file];
        $this->assertSame($payload, $lint->attach($payload, $file, static fn (): bool => false));
    }

    public function testAttachSwallowsDriverFailures(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/ok.php';
        file_put_contents($file, "<?php\n\nreturn 1;\n");

        $lint = new class () extends Linter {
            public function supports(string $path): bool
            {
                return str_ends_with($path, '.php');
            }

            public function lint(string $path, callable $shouldAbort, bool $deep = false): array
            {
                throw new RuntimeException('boom');
            }
        };

        $payload = ['path' => $file];
        $this->assertSame($payload, $lint->attach($payload, $file, static fn (): bool => false));
    }
}
