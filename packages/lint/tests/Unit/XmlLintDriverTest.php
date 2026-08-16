<?php

declare(strict_types=1);

namespace Switon\Lint\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Lint\Driver\XmlLintDriver;
use Switon\Lint\Tests\TestCase;

#[CoversClass(XmlLintDriver::class)]
class XmlLintDriverTest extends TestCase
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
        $dir = sys_get_temp_dir() . '/switon-lint-xml-' . uniqid('', true);
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

    public function testValidXml(): void
    {
        $file = $this->tempDir() . '/ok.xml';
        file_put_contents($file, "<root><a/></root>\n");

        $result = $this->make(XmlLintDriver::class)->lint($file, static fn (): bool => false);

        $this->assertSame([['name' => 'xml', 'ok' => true]], $result['tools']);
        $this->assertSame([], $result['diagnostics']);
    }

    public function testInvalidXml(): void
    {
        $file = $this->tempDir() . '/bad.xml';
        file_put_contents($file, "<a>\n");

        $result = $this->make(XmlLintDriver::class)->lint($file, static fn (): bool => false);

        $this->assertSame([['name' => 'xml', 'ok' => false]], $result['tools']);
        $this->assertNotEmpty($result['diagnostics']);
        $this->assertGreaterThanOrEqual(1, $result['diagnostics'][0]['line']);
    }

    public function testAbortReturnsWithoutReading(): void
    {
        $result = $this->make(XmlLintDriver::class)->lint('/tmp/none.xml', static fn (): bool => true);

        $this->assertSame('lint aborted', $result['diagnostics'][0]['message']);
    }

    public function testMissingFileIsADiagnostic(): void
    {
        $file = $this->tempDir() . '/missing.xml';

        $result = $this->make(XmlLintDriver::class)->lint($file, static fn (): bool => false);

        $this->assertSame('failed to read file', $result['diagnostics'][0]['message']);
    }

    public function testEmptyFileIsInvalidXml(): void
    {
        $file = $this->tempDir() . '/empty.xml';
        file_put_contents($file, '');

        $result = $this->make(XmlLintDriver::class)->lint($file, static fn (): bool => false);

        $this->assertSame('invalid XML', $result['diagnostics'][0]['message']);
    }
}
