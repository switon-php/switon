<?php

declare(strict_types=1);

namespace Switon\Lint\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Lint\Driver\PhpLintDriver;
use Switon\Lint\ProcessInterface;
use Switon\Lint\Tests\TestCase;

#[CoversClass(PhpLintDriver::class)]
class PhpLintDriverTest extends TestCase
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
        $dir = sys_get_temp_dir() . '/switon-lint-php-' . uniqid('', true);
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

    /**
     * @param array{bin: string, config: ?string, cwd: string}|null $phpstan
     */
    protected function driverWithProcess(ProcessInterface $process, ?array $phpstan = null): PhpLintDriver
    {
        $driver = $this->make(FixedPhpstanDriver::class, ['process' => $process]);
        $driver->fixed = $phpstan;

        return $driver;
    }

    public function testParsesPhpstanJsonMessages(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/typed.php';
        file_put_contents($file, "<?php\n\nreturn 1;\n");

        $process = new class () implements ProcessInterface {
            public int $calls = 0;

            public function run(
                array $command,
                callable $shouldAbort,
                ?string $workingDirectory = null,
                ?int $maxOutputBytes = null,
            ): array {
                $this->calls++;
                if (($command[1] ?? '') === '-l') {
                    return ['exit' => 0, 'stdout' => "No syntax errors detected\n", 'stderr' => ''];
                }

                $path = $command[array_key_last($command)];
                $json = json_encode([
                    'files' => [
                        $path => [
                            'messages' => [
                                [
                                    'message' => 'Dummy error',
                                    'line' => 3,
                                    'identifier' => 'test.dummy',
                                ],
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR);

                return ['exit' => 1, 'stdout' => $json, 'stderr' => ''];
            }
        };

        $result = $this->driverWithProcess($process, [
            'bin' => '/fake/phpstan',
            'config' => null,
            'cwd' => $dir,
        ])->lint($file, static fn (): bool => false, true);

        $this->assertSame('Dummy error', $result['diagnostics'][0]['message']);
        $this->assertSame(3, $result['diagnostics'][0]['line']);
        $this->assertSame('test.dummy', $result['diagnostics'][0]['code']);
        $this->assertSame([
            ['name' => 'php -l', 'ok' => true],
            ['name' => 'phpstan', 'ok' => false],
        ], $result['tools']);
        $this->assertSame(2, $process->calls);
    }

    public function testDeepFalseSkipsPhpstan(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/typed.php';
        file_put_contents($file, "<?php\n\nreturn 1;\n");

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

        $result = $this->driverWithProcess($process, [
            'bin' => '/fake/phpstan',
            'config' => null,
            'cwd' => $dir,
        ])->lint($file, static fn (): bool => false, false);

        $this->assertSame([['name' => 'php -l', 'ok' => true]], $result['tools']);
        $this->assertSame([], $result['diagnostics']);
        $this->assertSame(1, $process->calls);
    }

    public function testPhpstanNonJsonStdoutBecomesDiagnostic(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/x.php';
        file_put_contents($file, "<?php\n");

        $process = new class () implements ProcessInterface {
            public function run(
                array $command,
                callable $shouldAbort,
                ?string $workingDirectory = null,
                ?int $maxOutputBytes = null,
            ): array {
                if (($command[1] ?? '') === '-l') {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }

                return ['exit' => 1, 'stdout' => 'not-json', 'stderr' => ''];
            }
        };

        $result = $this->driverWithProcess($process, [
            'bin' => '/fake/phpstan',
            'config' => null,
            'cwd' => $dir,
        ])->lint($file, static fn (): bool => false, true);

        $this->assertSame('phpstan returned non-JSON output', $result['diagnostics'][0]['message']);
        $this->assertSame([
            ['name' => 'php -l', 'ok' => true],
            ['name' => 'phpstan', 'ok' => false],
        ], $result['tools']);
    }

    public function testPhpstanEmptyStdoutUsesStderr(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/x.php';
        file_put_contents($file, "<?php\n");

        $process = new class () implements ProcessInterface {
            public function run(
                array $command,
                callable $shouldAbort,
                ?string $workingDirectory = null,
                ?int $maxOutputBytes = null,
            ): array {
                if (($command[1] ?? '') === '-l') {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }

                return ['exit' => 1, 'stdout' => '', 'stderr' => "fatal: boom\n"];
            }
        };

        $result = $this->driverWithProcess($process, [
            'bin' => '/fake/phpstan',
            'config' => null,
            'cwd' => $dir,
        ])->lint($file, static fn (): bool => false, true);

        $this->assertStringContainsString('fatal: boom', $result['diagnostics'][0]['message']);
        $this->assertSame([
            ['name' => 'php -l', 'ok' => true],
            ['name' => 'phpstan', 'ok' => false],
        ], $result['tools']);
    }

    public function testPassesConfigurationFlagWhenNeonPresent(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/x.php';
        file_put_contents($file, "<?php\n");
        $neon = $dir . '/phpstan.neon';
        file_put_contents($neon, "parameters:\n    level: 0\n");

        $process = new class () implements ProcessInterface {
            /** @var list<string>|null */
            public ?array $phpstanCommand = null;

            public function run(
                array $command,
                callable $shouldAbort,
                ?string $workingDirectory = null,
                ?int $maxOutputBytes = null,
            ): array {
                if (($command[1] ?? '') === '-l') {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }
                $this->phpstanCommand = $command;

                return ['exit' => 0, 'stdout' => '{"files":{}}', 'stderr' => ''];
            }
        };

        $this->driverWithProcess($process, [
            'bin' => '/fake/phpstan',
            'config' => $neon,
            'cwd' => $dir,
        ])->lint($file, static fn (): bool => false, true);

        $this->assertNotNull($process->phpstanCommand);
        $this->assertContains('--configuration=' . $neon, $process->phpstanCommand);
    }

    public function testDiscoverWalksToAncestorVendorAndNeon(): void
    {
        $root = $this->tempDir();
        $nested = $root . '/packages/foo/src';
        mkdir($nested, 0777, true);
        mkdir($root . '/vendor/bin', 0777, true);
        $bin = $root . '/vendor/bin/phpstan';
        file_put_contents($bin, "#!/usr/bin/env php\n<?php\n");
        $neon = $root . '/phpstan.neon';
        file_put_contents($neon, "parameters:\n    level: 0\n");
        $file = $nested . '/A.php';
        file_put_contents($file, "<?php\n");

        $driver = $this->make(OpenPhpLintDriver::class);
        $found = $driver->discover($file);

        $this->assertNotNull($found);
        $slash = static fn (string $path): string => strtr($path, '\\', '/');
        $this->assertSame($slash($bin), $slash($found['bin']));
        $this->assertSame($slash($neon), $slash((string) $found['config']));
        $this->assertSame($slash($root), $slash($found['cwd']));
    }

    public function testDiscoverReturnsNullWithoutVendor(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/alone.php';
        file_put_contents($file, "<?php\n");

        $driver = $this->make(OpenPhpLintDriver::class);

        $this->assertNull($driver->discover($file));
    }

    public function testResolveCachesUnderProjectThenRediscoversOutside(): void
    {
        $rootA = $this->tempDir();
        $rootB = $this->tempDir();
        foreach ([$rootA, $rootB] as $root) {
            mkdir($root . '/src', 0777, true);
            mkdir($root . '/vendor/bin', 0777, true);
            file_put_contents($root . '/vendor/bin/phpstan', "#!/usr/bin/env php\n<?php\n");
        }
        $a1 = $rootA . '/src/A.php';
        $a2 = $rootA . '/src/B.php';
        $b1 = $rootB . '/src/C.php';
        file_put_contents($a1, "<?php\n");
        file_put_contents($a2, "<?php\n");
        file_put_contents($b1, "<?php\n");

        $driver = $this->make(CountingPhpLintDriver::class);

        $first = $driver->resolve($a1);
        $second = $driver->resolve($a2);
        $third = $driver->resolve($b1);

        $this->assertSame($first, $second);
        $this->assertNotSame($first['cwd'], $third['cwd']);
        $this->assertSame(2, $driver->discovers);
    }

    public function testResolveFallsBackWhenTheFileIsMissing(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/src', 0777, true);
        mkdir($root . '/vendor/bin', 0777, true);
        file_put_contents($root . '/vendor/bin/phpstan', "#!/usr/bin/env php\n<?php\n");
        $present = $root . '/src/A.php';
        file_put_contents($present, "<?php\n");

        $driver = $this->make(CountingPhpLintDriver::class);
        $driver->resolve($present);
        $again = $driver->resolve($root . '/src/missing.php');

        $this->assertSame($root, $again['cwd']);
    }

    public function testPhpSyntaxFailureKeepsTheReportedLine(): void
    {
        $process = new class () implements ProcessInterface {
            public function run(array $command, callable $shouldAbort, ?string $workingDirectory = null, ?int $maxOutputBytes = null): array
            {
                return ['exit' => 255, 'stdout' => '', 'stderr' => 'Parse error: syntax error on line 4'];
            }
        };

        $result = $this->driverWithProcess($process)->lint('/tmp/bad.php', static fn (): bool => false);

        $this->assertSame(4, $result['diagnostics'][0]['line']);
        $this->assertSame([['name' => 'php -l', 'ok' => false]], $result['tools']);
    }

    public function testPhpSyntaxFailureWithoutOutputUsesAFallback(): void
    {
        $process = new class () implements ProcessInterface {
            public function run(array $command, callable $shouldAbort, ?string $workingDirectory = null, ?int $maxOutputBytes = null): array
            {
                return ['exit' => 1, 'stdout' => '', 'stderr' => ''];
            }
        };

        $result = $this->driverWithProcess($process)->lint('/tmp/bad.php', static fn (): bool => false);

        $this->assertSame('php -l failed', $result['diagnostics'][0]['message']);
    }

    public function testPhpstanEmptySuccessAddsNoDiagnostic(): void
    {
        $process = new class () implements ProcessInterface {
            public function run(array $command, callable $shouldAbort, ?string $workingDirectory = null, ?int $maxOutputBytes = null): array
            {
                if (($command[1] ?? '') === '-l') {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }

                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            }
        };

        $result = $this->driverWithProcess($process, [
            'bin' => '/fake/phpstan',
            'config' => null,
            'cwd' => '/tmp',
        ])->lint('/tmp/ok.php', static fn (): bool => false, true);

        $this->assertSame([], $result['diagnostics']);
    }

    public function testPhpstanStderrBecomesTheDiagnostic(): void
    {
        $process = new class () implements ProcessInterface {
            public function run(array $command, callable $shouldAbort, ?string $workingDirectory = null, ?int $maxOutputBytes = null): array
            {
                if (($command[1] ?? '') === '-l') {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }

                return ['exit' => 1, 'stdout' => '', 'stderr' => "could not boot\n"];
            }
        };

        $result = $this->driverWithProcess($process, [
            'bin' => '/fake/phpstan',
            'config' => null,
            'cwd' => '/tmp',
        ])->lint('/tmp/ok.php', static fn (): bool => false, true);

        $this->assertSame('could not boot', $result['diagnostics'][0]['message']);
    }

    public function testPhpstanSkipsMalformedFileEntries(): void
    {
        $process = new class () implements ProcessInterface {
            public function run(array $command, callable $shouldAbort, ?string $workingDirectory = null, ?int $maxOutputBytes = null): array
            {
                if (($command[1] ?? '') === '-l') {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }

                return ['exit' => 1, 'stdout' => '{"files":{"a":"nope","b":{"messages":"nope"},"c":{"messages":["nope",{"message":""},{"message":"kept","line":2}]}}}', 'stderr' => ''];
            }
        };

        $result = $this->driverWithProcess($process, [
            'bin' => '/fake/phpstan',
            'config' => null,
            'cwd' => '/tmp',
        ])->lint('/tmp/ok.php', static fn (): bool => false, true);

        $this->assertSame('kept', $result['diagnostics'][0]['message']);
        $this->assertCount(1, $result['diagnostics']);
    }

    public function testPhpstanRejectsNonJson(): void
    {
        $process = new class () implements ProcessInterface {
            public function run(array $command, callable $shouldAbort, ?string $workingDirectory = null, ?int $maxOutputBytes = null): array
            {
                if (($command[1] ?? '') === '-l') {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }

                return ['exit' => 1, 'stdout' => 'not-json', 'stderr' => ''];
            }
        };

        $result = $this->driverWithProcess($process, [
            'bin' => '/fake/phpstan',
            'config' => null,
            'cwd' => '/tmp',
        ])->lint('/tmp/ok.php', static fn (): bool => false, true);

        $this->assertSame('phpstan returned non-JSON output', $result['diagnostics'][0]['message']);
    }
}

/**
 * @internal
 */
class FixedPhpstanDriver extends PhpLintDriver
{
    /** @var array{bin: string, config: ?string, cwd: string}|null */
    public ?array $fixed = null;

    protected function resolvePhpstan(string $path): ?array
    {
        return $this->fixed;
    }
}

/**
 * @internal
 */
class OpenPhpLintDriver extends PhpLintDriver
{
    /** @return array{bin: string, config: ?string, cwd: string}|null */
    public function discover(string $path): ?array
    {
        return $this->discoverPhpstan($path);
    }
}

/**
 * @internal
 */
class CountingPhpLintDriver extends PhpLintDriver
{
    public int $discovers = 0;

    /** @return array{bin: string, config: ?string, cwd: string}|null */
    public function resolve(string $path): ?array
    {
        return $this->resolvePhpstan($path);
    }

    protected function discoverPhpstan(string $path): ?array
    {
        $this->discovers++;

        return parent::discoverPhpstan($path);
    }
}
