<?php

declare(strict_types=1);

namespace Switon\Lint\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Lint\Driver\PythonLintDriver;
use Switon\Lint\ProcessInterface;
use Switon\Lint\Tests\TestCase;

#[CoversClass(PythonLintDriver::class)]
class PythonLintDriverTest extends TestCase
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
        $dir = sys_get_temp_dir() . '/switon-lint-py-' . uniqid('', true);
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

    protected function driverWithProcess(ProcessInterface $process): PythonLintDriver
    {
        return $this->make(PythonLintDriver::class, ['process' => $process]);
    }

    public function testSyntaxError(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/bad.py';
        file_put_contents($file, "def f(\n");

        $process = new class () implements ProcessInterface {
            public function run(
                array $command,
                callable $shouldAbort,
                ?string $workingDirectory = null,
                ?int $maxOutputBytes = null,
            ): array {
                return [
                    'exit' => 1,
                    'stdout' => '',
                    'stderr' => "  File \"{$command[3]}\", line 1\n    def f(\n         ^\nSyntaxError: '(' was never closed\n",
                ];
            }
        };

        $result = $this->driverWithProcess($process)->lint($file, static fn (): bool => false);

        $this->assertSame([['name' => 'python', 'ok' => false]], $result['tools']);
        $this->assertSame(1, $result['diagnostics'][0]['line']);
        $this->assertStringContainsString('SyntaxError', $result['diagnostics'][0]['message']);
    }

    public function testDeepIgnoredStillSyntaxOnly(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/ok.py';
        file_put_contents($file, "x = 1\n");

        $process = new class () implements ProcessInterface {
            public int $calls = 0;

            public function run(
                array $command,
                callable $shouldAbort,
                ?string $workingDirectory = null,
                ?int $maxOutputBytes = null,
            ): array {
                $this->calls++;

                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            }
        };

        $result = $this->driverWithProcess($process)->lint($file, static fn (): bool => false, true);

        $this->assertSame([['name' => 'python', 'ok' => true]], $result['tools']);
        $this->assertSame(1, $process->calls);
    }
}
