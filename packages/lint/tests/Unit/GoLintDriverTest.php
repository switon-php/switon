<?php

declare(strict_types=1);

namespace Switon\Lint\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Lint\Driver\GoLintDriver;
use Switon\Lint\ProcessInterface;
use Switon\Lint\Tests\TestCase;

#[CoversClass(GoLintDriver::class)]
class GoLintDriverTest extends TestCase
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
        $dir = sys_get_temp_dir() . '/switon-lint-go-' . uniqid('', true);
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
     * @param array{cwd: string}|null $module
     */
    protected function driverWithProcess(ProcessInterface $process, ?array $module = null): GoLintDriver
    {
        $driver = $this->make(FixedGoModuleDriver::class, ['process' => $process]);
        $driver->fixed = $module;

        return $driver;
    }

    public function testGofmtSyntaxError(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/bad.go';
        file_put_contents($file, "package main\n\nfunc main() {\n\tx :=\n}\n");

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
                    'stderr' => "{$command[2]}:5:1: expected operand, found '}'\n",
                ];
            }
        };

        $result = $this->driverWithProcess($process, null)->lint($file, static fn (): bool => false);

        $this->assertSame([['name' => 'gofmt', 'ok' => false]], $result['tools']);
        $this->assertSame(5, $result['diagnostics'][0]['line']);
        $this->assertStringContainsString('expected operand', $result['diagnostics'][0]['message']);
    }

    public function testGoVetParsesDiagnostics(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/t.go';
        file_put_contents($file, "package t\n\nfunc F() { x := 1 }\n");

        $process = new class () implements ProcessInterface {
            public int $calls = 0;

            public function run(
                array $command,
                callable $shouldAbort,
                ?string $workingDirectory = null,
                ?int $maxOutputBytes = null,
            ): array {
                $this->calls++;
                if (($command[0] ?? '') === 'gofmt' || str_ends_with((string) ($command[0] ?? ''), 'gofmt')) {
                    return ['exit' => 0, 'stdout' => "package t\n", 'stderr' => ''];
                }

                return [
                    'exit' => 1,
                    'stdout' => '',
                    'stderr' => "# example.com/t\nvet: ./t.go:3:12: declared and not used: x\n",
                ];
            }
        };

        $result = $this->driverWithProcess($process, ['cwd' => $dir])->lint($file, static fn (): bool => false, true);

        $this->assertSame([
            ['name' => 'gofmt', 'ok' => true],
            ['name' => 'go vet', 'ok' => false],
        ], $result['tools']);
        $this->assertSame(3, $result['diagnostics'][0]['line']);
        $this->assertSame('declared and not used: x', $result['diagnostics'][0]['message']);
        $this->assertSame(2, $process->calls);
    }

    public function testDiscoverWalksToGoMod(): void
    {
        $root = $this->tempDir();
        $nested = $root . '/pkg';
        mkdir($nested);
        file_put_contents($root . '/go.mod', "module example.com/t\n\ngo 1.22\n");
        $file = $nested . '/a.go';
        file_put_contents($file, "package pkg\n");

        $driver = $this->make(OpenGoLintDriver::class);
        $found = $driver->discover($file);

        $this->assertSame(['cwd' => $root], $found);
    }

    public function testDiscoverReturnsNullWithoutGoMod(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/alone.go';
        file_put_contents($file, "package alone\n");

        $driver = $this->make(OpenGoLintDriver::class);
        $this->assertNull($driver->discover($file));
    }

    public function testResolveCachesUnderModuleThenRediscoversOutside(): void
    {
        $a = $this->tempDir();
        $b = $this->tempDir();
        file_put_contents($a . '/go.mod', "module a\n\ngo 1.22\n");
        file_put_contents($b . '/go.mod', "module b\n\ngo 1.22\n");
        $a1 = $a . '/one.go';
        $a2 = $a . '/two.go';
        $b1 = $b . '/one.go';
        file_put_contents($a1, "package a\n");
        file_put_contents($a2, "package a\n");
        file_put_contents($b1, "package b\n");

        $driver = $this->make(CountingGoLintDriver::class);
        $first = $driver->resolve($a1);
        $second = $driver->resolve($a2);
        $third = $driver->resolve($b1);

        $this->assertSame($first, $second);
        $this->assertNotSame($first['cwd'] ?? null, $third['cwd'] ?? null);
        $this->assertSame(2, $driver->discovers);
    }

    public function testGofmtFailureWithoutALocationUsesTheBlob(): void
    {
        $process = new class () implements ProcessInterface {
            public function run(array $command, callable $shouldAbort, ?string $workingDirectory = null, ?int $maxOutputBytes = null): array
            {
                return ['exit' => 2, 'stdout' => '', 'stderr' => "# comment\nnot a location\n"];
            }
        };

        $result = $this->driverWithProcess($process)->lint('/tmp/bad.go', static fn (): bool => false);

        $this->assertSame("# comment not a location", $result['diagnostics'][0]['message']);
    }

    public function testGoVetSuccessAddsNoDiagnostic(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/ok.go';
        file_put_contents($file, "package ok\n");
        $process = new class () implements ProcessInterface {
            public function run(array $command, callable $shouldAbort, ?string $workingDirectory = null, ?int $maxOutputBytes = null): array
            {
                return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
            }
        };

        $result = $this->driverWithProcess($process, ['cwd' => $dir])->lint($file, static fn (): bool => false, true);

        $this->assertSame([], $result['diagnostics']);
    }

    public function testGoVetFailureWithoutALocationUsesTheBlob(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/bad.go';
        file_put_contents($file, "package bad\n");
        $process = new class () implements ProcessInterface {
            public function run(array $command, callable $shouldAbort, ?string $workingDirectory = null, ?int $maxOutputBytes = null): array
            {
                if (($command[0] ?? '') === 'gofmt') {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }

                return ['exit' => 1, 'stdout' => '', 'stderr' => "vet failed\n"];
            }
        };

        $result = $this->driverWithProcess($process, ['cwd' => $dir])->lint($file, static fn (): bool => false, true);

        $this->assertSame('vet failed', $result['diagnostics'][0]['message']);
    }

    public function testGoVetEmptyFailureAddsNoDiagnostic(): void
    {
        $dir = $this->tempDir();
        $file = $dir . '/bad.go';
        file_put_contents($file, "package bad\n");
        $process = new class () implements ProcessInterface {
            public function run(array $command, callable $shouldAbort, ?string $workingDirectory = null, ?int $maxOutputBytes = null): array
            {
                if (($command[0] ?? '') === 'gofmt') {
                    return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
                }

                return ['exit' => 1, 'stdout' => '', 'stderr' => ''];
            }
        };

        $result = $this->driverWithProcess($process, ['cwd' => $dir])->lint($file, static fn (): bool => false, true);

        $this->assertSame([], $result['diagnostics']);
    }
}

/** @internal */
class FixedGoModuleDriver extends GoLintDriver
{
    /** @var array{cwd: string}|null */
    public ?array $fixed = null;

    protected function resolveModule(string $path): ?array
    {
        return $this->fixed;
    }
}

/** @internal */
class OpenGoLintDriver extends GoLintDriver
{
    /** @return array{cwd: string}|null */
    public function discover(string $path): ?array
    {
        return $this->discoverModule($path);
    }
}

/** @internal */
class CountingGoLintDriver extends GoLintDriver
{
    public int $discovers = 0;

    /** @return array{cwd: string}|null */
    public function resolve(string $path): ?array
    {
        return $this->resolveModule($path);
    }

    protected function discoverModule(string $path): ?array
    {
        $this->discovers++;

        return parent::discoverModule($path);
    }
}
