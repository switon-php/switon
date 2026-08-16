<?php

declare(strict_types=1);

namespace Switon\Lint\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Lint\Driver\BashLintDriver;
use Switon\Lint\ProcessInterface;
use Switon\Lint\Tests\TestCase;

#[CoversClass(BashLintDriver::class)]
class BashLintDriverTest extends TestCase
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
        $dir = sys_get_temp_dir() . '/switon-lint-bash-' . uniqid('', true);
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

    protected function driverWithProcess(ProcessInterface $process, string|false|null $shellcheck = false): BashLintDriver
    {
        $driver = $this->make(ShellcheckProbe::class, ['process' => $process]);
        $driver->remember($shellcheck);

        return $driver;
    }

    public function testBashSyntaxError(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/bad.sh';
        file_put_contents($file, "#!/bin/bash\nif true\nfi\n");

        $process = new class () implements ProcessInterface {
            public function run(
                array $command,
                callable $shouldAbort,
                ?string $workingDirectory = null,
                ?int $maxOutputBytes = null,
            ): array {
                return [
                    'exit' => 2,
                    'stdout' => '',
                    'stderr' => "{$command[2]}: line 3: syntax error near unexpected token `fi'\n",
                ];
            }
        };

        $result = $this->driverWithProcess($process)->lint($file, static fn (): bool => false);

        $this->assertSame([['name' => 'bash -n', 'ok' => false]], $result['tools']);
        $this->assertSame(3, $result['diagnostics'][0]['line']);
        $this->assertStringContainsString('syntax error', $result['diagnostics'][0]['message']);
    }

    public function testShellcheckJsonWhenDeep(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/x.sh';
        file_put_contents($file, "#!/bin/bash\necho hi\n");

        $process = new class () implements ProcessInterface {
            public int $calls = 0;

            public function run(
                array $command,
                callable $shouldAbort,
                ?string $workingDirectory = null,
                ?int $maxOutputBytes = null,
            ): array {
                $this->calls++;
                if (($command[1] ?? '') === '-n') {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }

                $json = json_encode([
                    [
                        'file' => $command[array_key_last($command)],
                        'line' => 2,
                        'level' => 'warning',
                        'code' => 2086,
                        'message' => 'Double quote to prevent globbing',
                    ],
                ], JSON_THROW_ON_ERROR);

                return ['exit' => 1, 'stdout' => $json, 'stderr' => ''];
            }
        };

        $result = $this->driverWithProcess($process, '/fake/shellcheck')->lint(
            $file,
            static fn (): bool => false,
            true,
        );

        $this->assertSame([
            ['name' => 'bash -n', 'ok' => true],
            ['name' => 'shellcheck', 'ok' => false],
        ], $result['tools']);
        $this->assertSame(2, $result['diagnostics'][0]['line']);
        $this->assertSame('warning', $result['diagnostics'][0]['severity']);
        $this->assertSame('SC2086', $result['diagnostics'][0]['code']);
        $this->assertSame(2, $process->calls);
    }

    public function testDeepFalseSkipsShellcheck(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/x.sh';
        file_put_contents($file, "#!/bin/bash\necho hi\n");

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

        $result = $this->driverWithProcess($process, '/fake/shellcheck')->lint(
            $file,
            static fn (): bool => false,
            false,
        );

        $this->assertSame([['name' => 'bash -n', 'ok' => true]], $result['tools']);
        $this->assertSame(1, $process->calls);
    }

    public function testShellcheckMissingBinaryIsSkipped(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/x.sh';
        file_put_contents($file, "#!/bin/bash\necho hi\n");
        $process = new class () implements ProcessInterface {
            public int $calls = 0;

            public function run(array $command, callable $shouldAbort, ?string $workingDirectory = null, ?int $maxOutputBytes = null): array
            {
                $this->calls++;

                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            }
        };

        $driver = $this->make(ShellcheckProbe::class, [
            'process' => $process,
            'shellcheckBinary' => '',
        ]);
        $result = $driver->lint($file, static fn (): bool => false, true);
        $this->driverWithProcess($process)->lint($file, static fn (): bool => false, true);

        $this->assertSame([['name' => 'bash -n', 'ok' => true]], $result['tools']);
        $this->assertSame(2, $process->calls);
    }

    public function testShellcheckStderrBecomesTheDiagnostic(): void
    {
        $process = new class () implements ProcessInterface {
            public function run(array $command, callable $shouldAbort, ?string $workingDirectory = null, ?int $maxOutputBytes = null): array
            {
                if (($command[1] ?? '') === '-n') {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }

                return ['exit' => 1, 'stdout' => '', 'stderr' => "shellcheck broke\n"];
            }
        };

        $result = $this->driverWithProcess($process, '/fake/shellcheck')->lint('/tmp/x.sh', static fn (): bool => false, true);

        $this->assertSame('shellcheck broke', $result['diagnostics'][0]['message']);
    }

    public function testShellcheckRejectsNonJson(): void
    {
        $process = new class () implements ProcessInterface {
            public function run(array $command, callable $shouldAbort, ?string $workingDirectory = null, ?int $maxOutputBytes = null): array
            {
                if (($command[1] ?? '') === '-n') {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }

                return ['exit' => 1, 'stdout' => 'nope', 'stderr' => ''];
            }
        };

        $result = $this->driverWithProcess($process, '/fake/shellcheck')->lint('/tmp/x.sh', static fn (): bool => false, true);

        $this->assertSame('shellcheck returned non-JSON output', $result['diagnostics'][0]['message']);
    }

    public function testShellcheckSkipsBlankItemsAndKeepsAStringCode(): void
    {
        $process = new class () implements ProcessInterface {
            public function run(array $command, callable $shouldAbort, ?string $workingDirectory = null, ?int $maxOutputBytes = null): array
            {
                if (($command[1] ?? '') === '-n') {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }

                return [
                    'exit' => 1,
                    'stdout' => '["skip",{"message":""},{"message":"kept","line":4,"level":"info","code":"SC1000"},{"message":"bad","line":1,"level":"error"}]',
                    'stderr' => '',
                ];
            }
        };

        $result = $this->driverWithProcess($process, '/fake/shellcheck')->lint('/tmp/x.sh', static fn (): bool => false, true);

        $this->assertSame('kept', $result['diagnostics'][0]['message']);
        $this->assertSame('warning', $result['diagnostics'][0]['severity']);
        $this->assertSame('SC1000', $result['diagnostics'][0]['code']);
    }

    public function testShellcheckUsesABinaryThatExists(): void
    {
        $dir = $this->tempDir();
        $bin = $dir . '/shellcheck';
        file_put_contents($bin, "#!/bin/sh\n");
        $process = new class () implements ProcessInterface {
            public function run(array $command, callable $shouldAbort, ?string $workingDirectory = null, ?int $maxOutputBytes = null): array
            {
                if (($command[1] ?? '') === '-n') {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }

                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            }
        };

        $driver = $this->make(ShellcheckProbe::class, [
            'process' => $process,
            'shellcheckBinary' => $bin,
        ]);
        $result = $driver->lint($dir . '/x.sh', static fn (): bool => false, true);

        $this->assertSame([
            ['name' => 'bash -n', 'ok' => true],
            ['name' => 'shellcheck', 'ok' => true],
        ], $result['tools']);
    }

    public function testShellcheckNameMissingFromPathIsSkipped(): void
    {
        $process = new class () implements ProcessInterface {
            public int $calls = 0;

            public function run(array $command, callable $shouldAbort, ?string $workingDirectory = null, ?int $maxOutputBytes = null): array
            {
                $this->calls++;

                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            }
        };
        $driver = $this->make(ShellcheckProbe::class, [
            'process' => $process,
            'shellcheckBinary' => 'switon-lint-shellcheck-missing',
        ]);

        $result = $driver->lint('/tmp/x.sh', static fn (): bool => false, true);

        $this->assertSame([['name' => 'bash -n', 'ok' => true]], $result['tools']);
        $this->assertSame(1, $process->calls);
    }

    public function testShellcheckEmptyFailureAddsNoDiagnostic(): void
    {
        $process = new class () implements ProcessInterface {
            public function run(array $command, callable $shouldAbort, ?string $workingDirectory = null, ?int $maxOutputBytes = null): array
            {
                if (($command[1] ?? '') === '-n') {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }

                return ['exit' => 1, 'stdout' => '', 'stderr' => ''];
            }
        };

        $result = $this->driverWithProcess($process, '/fake/shellcheck')->lint('/tmp/x.sh', static fn (): bool => false, true);

        $this->assertSame([], $result['diagnostics']);
    }
}

/** @internal */
class ShellcheckProbe extends BashLintDriver
{
    public function remember(string|false|null $shellcheck): void
    {
        $this->shellcheck = $shellcheck;
    }
}
