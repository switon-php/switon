<?php

declare(strict_types=1);

namespace Switon\Lint\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Lint\Driver\JsLintDriver;
use Switon\Lint\ProcessInterface;
use Switon\Lint\Tests\TestCase;

#[CoversClass(JsLintDriver::class)]
class JsLintDriverTest extends TestCase
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
        $dir = sys_get_temp_dir() . '/switon-lint-js-' . uniqid('', true);
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
     * @param array{bin: string, cwd: string}|null $eslint
     */
    protected function driverWithProcess(ProcessInterface $process, ?array $eslint = null): JsLintDriver
    {
        $driver = $this->make(FixedEslintDriver::class, ['process' => $process]);
        $driver->fixed = $eslint;

        return $driver;
    }

    public function testParsesEslintJsonMessages(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/x.js';
        file_put_contents($file, "const x = 1;\n");

        $process = new class () implements ProcessInterface {
            public int $calls = 0;

            public function run(
                array $command,
                callable $shouldAbort,
                ?string $workingDirectory = null,
                ?int $maxOutputBytes = null,
            ): array {
                $this->calls++;
                if (($command[1] ?? '') === '--check') {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }

                $json = json_encode([
                    [
                        'filePath' => $command[array_key_last($command)],
                        'messages' => [
                            [
                                'ruleId' => 'no-unused-vars',
                                'severity' => 2,
                                'message' => "'x' is assigned a value but never used.",
                                'line' => 1,
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR);

                return ['exit' => 1, 'stdout' => $json, 'stderr' => ''];
            }
        };

        $result = $this->driverWithProcess($process, [
            'bin' => '/fake/eslint',
            'cwd' => $dir,
        ])->lint($file, static fn (): bool => false, true);

        $this->assertSame("'x' is assigned a value but never used.", $result['diagnostics'][0]['message']);
        $this->assertSame(1, $result['diagnostics'][0]['line']);
        $this->assertSame('no-unused-vars', $result['diagnostics'][0]['code']);
        $this->assertSame([
            ['name' => 'node --check', 'ok' => true],
            ['name' => 'eslint', 'ok' => false],
        ], $result['tools']);
        $this->assertSame(2, $process->calls);
    }

    public function testEslintNonJsonStdoutBecomesDiagnostic(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/x.js';
        file_put_contents($file, "const x = 1;\n");

        $process = new class () implements ProcessInterface {
            public function run(
                array $command,
                callable $shouldAbort,
                ?string $workingDirectory = null,
                ?int $maxOutputBytes = null,
            ): array {
                if (($command[1] ?? '') === '--check') {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }

                return ['exit' => 1, 'stdout' => 'not-json', 'stderr' => ''];
            }
        };

        $result = $this->driverWithProcess($process, [
            'bin' => '/fake/eslint',
            'cwd' => $dir,
        ])->lint($file, static fn (): bool => false, true);

        $this->assertSame('eslint returned non-JSON output', $result['diagnostics'][0]['message']);
        $this->assertSame([
            ['name' => 'node --check', 'ok' => true],
            ['name' => 'eslint', 'ok' => false],
        ], $result['tools']);
    }

    public function testEslintEmptyStdoutUsesStderr(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/x.js';
        file_put_contents($file, "const x = 1;\n");

        $process = new class () implements ProcessInterface {
            public function run(
                array $command,
                callable $shouldAbort,
                ?string $workingDirectory = null,
                ?int $maxOutputBytes = null,
            ): array {
                if (($command[1] ?? '') === '--check') {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }

                return ['exit' => 1, 'stdout' => '', 'stderr' => "ESLint couldn't find a configuration file.\n"];
            }
        };

        $result = $this->driverWithProcess($process, [
            'bin' => '/fake/eslint',
            'cwd' => $dir,
        ])->lint($file, static fn (): bool => false, true);

        $this->assertStringContainsString("couldn't find a configuration", $result['diagnostics'][0]['message']);
        $this->assertSame([
            ['name' => 'node --check', 'ok' => true],
            ['name' => 'eslint', 'ok' => false],
        ], $result['tools']);
    }

    public function testNodeCheckSyntaxError(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/bad.js';
        file_put_contents($file, "const x = (\n");

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
                    'stderr' => "{$command[2]}:1\nSyntaxError: Unexpected end of input\n",
                ];
            }
        };

        $result = $this->driverWithProcess($process, null)->lint($file, static fn (): bool => false);

        $this->assertSame([['name' => 'node --check', 'ok' => false]], $result['tools']);
        $this->assertSame(1, $result['diagnostics'][0]['line']);
        $this->assertStringContainsString('Unexpected end of input', $result['diagnostics'][0]['message']);
    }

    public function testDiscoverWalksToAncestorNodeModules(): void
    {
        $root = $this->tempDir();
        $nested = $root . '/pkg/src';
        mkdir($nested, 0777, true);
        $binDir = $root . '/node_modules/.bin';
        mkdir($binDir, 0777, true);
        $bin = $binDir . '/eslint';
        file_put_contents($bin, "#!/bin/sh\n");
        chmod($bin, 0755);
        $file = $nested . '/a.js';
        file_put_contents($file, "const a = 1;\n");

        $driver = $this->make(OpenJsLintDriver::class);
        $found = $driver->discover($file);

        $slash = static fn (string $path): string => strtr($path, '\\', '/');
        $this->assertSame(
            ['bin' => $slash($bin), 'cwd' => $slash($root)],
            ['bin' => $slash($found['bin']), 'cwd' => $slash($found['cwd'])],
        );
    }

    public function testDiscoverReturnsNullWithoutEslint(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/alone.js';
        file_put_contents($file, "const a = 1;\n");

        $driver = $this->make(OpenJsLintDriver::class);
        $this->assertNull($driver->discover($file));
    }

    public function testResolveCachesUnderProjectThenRediscoversOutside(): void
    {
        $a = $this->tempDir();
        $b = $this->tempDir();
        foreach ([$a, $b] as $root) {
            $binDir = $root . '/node_modules/.bin';
            mkdir($binDir, 0777, true);
            file_put_contents($binDir . '/eslint', "#!/bin/sh\n");
            chmod($binDir . '/eslint', 0755);
        }
        $a1 = $a . '/one.js';
        $a2 = $a . '/two.js';
        $b1 = $b . '/one.js';
        file_put_contents($a1, "1\n");
        file_put_contents($a2, "2\n");
        file_put_contents($b1, "3\n");

        $driver = $this->make(CountingJsLintDriver::class);
        $first = $driver->resolve($a1);
        $second = $driver->resolve($a2);
        $third = $driver->resolve($b1);

        $this->assertSame($first, $second);
        $this->assertNotSame($first['cwd'] ?? null, $third['cwd'] ?? null);
        $this->assertSame(2, $driver->discovers);
    }
}

/** @internal */
class FixedEslintDriver extends JsLintDriver
{
    /** @var array{bin: string, cwd: string}|null */
    public ?array $fixed = null;

    protected function resolveEslint(string $path): ?array
    {
        return $this->fixed;
    }
}

/** @internal */
class OpenJsLintDriver extends JsLintDriver
{
    /** @return array{bin: string, cwd: string}|null */
    public function discover(string $path): ?array
    {
        return $this->discoverEslint($path);
    }
}

/** @internal */
class CountingJsLintDriver extends JsLintDriver
{
    public int $discovers = 0;

    /** @return array{bin: string, cwd: string}|null */
    public function resolve(string $path): ?array
    {
        return $this->resolveEslint($path);
    }

    protected function discoverEslint(string $path): ?array
    {
        $this->discovers++;

        return parent::discoverEslint($path);
    }
}
