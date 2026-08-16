<?php

declare(strict_types=1);

namespace Switon\Lint\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Lint\Driver\JsonLintDriver;
use Switon\Lint\Tests\TestCase;

#[CoversClass(JsonLintDriver::class)]
class JsonLintDriverTest extends TestCase
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
        $dir = sys_get_temp_dir() . '/switon-lint-json-' . uniqid('', true);
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

    public function testValidJson(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/ok.json';
        file_put_contents($file, "{\"a\": 1}\n");

        $result = $this->make(JsonLintDriver::class)->lint($file, static fn (): bool => false);

        $this->assertSame([['name' => 'json', 'ok' => true]], $result['tools']);
        $this->assertSame([], $result['diagnostics']);
    }

    public function testInvalidJson(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/bad.json';
        file_put_contents($file, "{\"a\": 1,}\n");

        $result = $this->make(JsonLintDriver::class)->lint($file, static fn (): bool => false);

        $this->assertSame([['name' => 'json', 'ok' => false]], $result['tools']);
        $this->assertNotEmpty($result['diagnostics']);
        $this->assertSame('error', $result['diagnostics'][0]['severity']);
    }

    public function testAbortReturnsWithoutReading(): void
    {
        $result = $this->make(JsonLintDriver::class)->lint('/tmp/none.json', static fn (): bool => true);

        $this->assertSame('lint aborted', $result['diagnostics'][0]['message']);
        $this->assertSame([['name' => 'json', 'ok' => false]], $result['tools']);
    }

    public function testMissingFileIsADiagnostic(): void
    {
        $file = $this->tempDir() . '/missing.json';

        $result = $this->make(JsonLintDriver::class)->lint($file, static fn (): bool => false);

        $this->assertSame('failed to read file', $result['diagnostics'][0]['message']);
    }
}
