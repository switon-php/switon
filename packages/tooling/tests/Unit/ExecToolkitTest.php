<?php

declare(strict_types=1);

namespace Switon\Tooling\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Tooling\ExecToolkit;
use Switon\Tooling\Tests\ToolTestCase;

use const PHP_OS_FAMILY;

#[CoversClass(ExecToolkit::class)]
class ExecToolkitTest extends ToolTestCase
{
    public function testSchemaReturnsSharedCachedInstance(): void
    {
        $tool = $this->make(ExecToolkit::class, ['commands' => ['other-name']]);
        $schema = $tool->schema('php');

        $this->assertNotNull($schema);
        $this->assertStringContainsString('Unlike `bash`', $schema->definition['description']);
        $this->assertArrayNotHasKey('max_output', $schema->definition['inputSchema']['properties']);
        $this->assertSame('array', $schema->definition['inputSchema']['properties']['argv']['type']);
        $this->assertSame('string', $schema->definition['inputSchema']['properties']['argv']['items']['type']);
        $this->assertArrayHasKey('cwd', $schema->definition['inputSchema']['properties']);
        $this->assertSame($schema, $tool->schema('other-name'));
    }

    public function testSchemaClaimsRegisteredUnderscoreCommand(): void
    {
        $default = $this->make(ExecToolkit::class);
        $this->assertNull($default->schema('mysql_upgrade'));

        $tool = $this->make(ExecToolkit::class, ['commands' => ['mysql_upgrade']]);
        $schema = $tool->schema('mysql_upgrade');
        $this->assertNotNull($schema);
        $this->assertStringContainsString('Unlike `bash`', $schema->definition['description']);
    }

    public function testSchemaClaimsOnlyNamesOutsideCatalogNamespace(): void
    {
        $tool = $this->make(ExecToolkit::class);

        $this->assertNotNull($tool->schema('php'));
        $this->assertNull($tool->schema('switon-not-a-command'));
        $this->assertNull($tool->schema('site_zhihuu'));
        $this->assertNull($tool->schema('task_deliver'));
        $this->assertNull($tool->schema(''));
        $this->assertNull($tool->schema('../echo'));
        $this->assertNull($tool->schema('app__tool'));
    }

    public function testAutoClaimIgnoresRelativePathEntries(): void
    {
        $prev = getenv('PATH') ?: '';
        putenv('PATH=.' . PATH_SEPARATOR . '..' . PATH_SEPARATOR . 'bin');
        try {
            $this->assertNull($this->make(ExecToolkit::class)->schema('echo'));
        } finally {
            putenv('PATH=' . $prev);
        }
    }

    public function testAutoClaimCachesResolvedExecutable(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('CreateProcess does not run an extensionless shell script.');
        }

        $dir = sys_get_temp_dir() . '/switon-exec-bin-' . uniqid('', true);
        mkdir($dir);
        $bin = $dir . '/demo-bin';
        file_put_contents($bin, "#!/bin/sh\necho cached\n");
        chmod($bin, 0755);

        $prev = getenv('PATH') ?: '';
        putenv('PATH=' . $dir);
        try {
            $tool = $this->make(ExecToolkit::class);
            $this->assertNotNull($tool->schema('demo-bin'));

            putenv('PATH=' . $prev);
            $payload = $this->callExec($tool, 'demo-bin', ['argv' => []]);
            $this->assertSame(0, $payload['exit']);
            $this->assertSame("cached\n", $payload['stdout']);

            $tool->reload();
            $this->assertNotNull($tool->schema('demo-bin'));
        } finally {
            putenv('PATH=' . $prev);
            @unlink($bin);
            @rmdir($dir);
        }
    }

    public function testSchemaClaimsConfiguredNames(): void
    {
        $tool = $this->make(ExecToolkit::class, ['commands' => ['../echo', 'app__tool', 'bin/echo']]);

        $this->assertNotNull($tool->schema('../echo'));
        $this->assertNotNull($tool->schema('app__tool'));
        $this->assertNotNull($tool->schema('bin/echo'));
    }

    public function testRegisteredExceptionIsClaimed(): void
    {
        $tool = $this->make(ExecToolkit::class, ['commands' => ['llvm_objdump']]);

        $this->assertNotNull($tool->schema('llvm_objdump'));
        $this->assertNull($tool->schema('llvm_other'));
    }

    public function testCallRunsNamedCommand(): void
    {
        $result = $this->callExec($this->make(ExecToolkit::class), 'php', [
            'argv' => ['-r', 'fwrite(STDOUT, "hello\n");'],
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertSame("hello\n", $result['stdout']);
    }

    public function testCallRunsBinaryDirectly(): void
    {
        $result = $this->callExec($this->make(ExecToolkit::class), 'php', [
            'argv' => ['-r', 'fwrite(STDOUT, "direct");'],
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertSame('direct', $result['stdout']);
    }

    public function testCallAllowsSpacedArguments(): void
    {
        $result = $this->callExec($this->make(ExecToolkit::class), 'php', [
            'argv' => ['-r', 'fwrite(STDOUT, $argv[1]);', 'hello world'],
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertSame('hello world', $result['stdout']);
    }

    public function testCallKeepsArgvThatRepeatsToolName(): void
    {
        $result = $this->callExec($this->make(ExecToolkit::class), 'php', [
            'argv' => ['-r', 'fwrite(STDOUT, $argv[1] . " " . $argv[2] . "\n");', 'echo', 'hello'],
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertSame("echo hello\n", $result['stdout']);
    }

    public function testCallAllowsEmptyArgv(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX echo with no arguments is not a Windows program.');
        }

        $result = $this->callExec($this->make(ExecToolkit::class), 'echo', ['argv' => []]);

        $this->assertSame(0, $result['exit']);
        $this->assertSame("\n", $result['stdout']);
    }

    public function testCallSpillsLargeStdout(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('CreateProcess rejects a command line this long.');
        }

        $tool = $this->make(ExecToolkit::class);
        $payload = str_repeat('x', 33 * 1024);
        $result = $this->callExec($tool, 'echo', [
            'argv' => ['-n', $payload],
        ]);

        $this->assertSame(0, $result['exit']);
        // Spill preview is wire text only — not the envelope truncated flag.
        $this->assertArrayNotHasKey('truncated', $result);
        $this->assertStringContainsString('recall: @thread/tool-result/test-stdout.txt', $result['stdout']);
        $this->assertStringContainsString('...[truncated:', $result['stdout']);
        $this->assertStringStartsWith(str_repeat('x', 1024), $result['stdout']);
        $this->assertStringEndsWith(str_repeat('x', 1024), $result['stdout']);
        $this->assertArrayNotHasKey('recall', $result);
    }

    public function testCallDoesNotMarkTruncatedWhenStdoutContainsRecallLiteral(): void
    {
        $result = $this->callExec($this->make(ExecToolkit::class), 'php', [
            'argv' => ['-r', 'fwrite(STDOUT, $argv[1]);', 'docs mention recall: tool-result/x.txt'],
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertSame('docs mention recall: tool-result/x.txt', $result['stdout']);
        $this->assertArrayNotHasKey('truncated', $result);
    }

    public function testCallPassesShellLookingArgvLiterally(): void
    {
        $result = $this->callExec($this->make(ExecToolkit::class), 'php', [
            'argv' => ['-r', 'fwrite(STDOUT, $argv[1]);', '$HOME'],
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertSame('$HOME', $result['stdout']);
    }

    public function testAutoClaimSkipsNonExecutableFile(): void
    {
        $dir = sys_get_temp_dir() . '/switon-exec-plain-' . uniqid('', true);
        mkdir($dir);
        $bin = $dir . '/plain-bin';
        file_put_contents($bin, "#!/bin/sh\necho no\n");
        chmod($bin, 0644);

        $prev = getenv('PATH') ?: '';
        putenv('PATH=' . $dir);
        try {
            $this->assertNull($this->make(ExecToolkit::class)->schema('plain-bin'));
        } finally {
            putenv('PATH=' . $prev);
            @unlink($bin);
            @rmdir($dir);
        }
    }

    public function testCallUsesExplicitCwd(): void
    {
        $cwd = sys_get_temp_dir() . '/switon-exec-cwd-' . uniqid('', true);
        mkdir($cwd);
        try {
            $result = $this->callExec($this->make(ExecToolkit::class), 'php', [
                'argv' => ['-r', 'fwrite(STDOUT, getcwd());'],
                'cwd' => $cwd,
            ]);
        } finally {
            @rmdir($cwd);
        }

        $this->assertSame(0, $result['exit']);
        $printed = rtrim(str_replace("\r", '', $result['stdout']));
        if (preg_match('#^/([A-Za-z])/(.*)$#', strtr($printed, '\\', '/'), $match) === 1) {
            $printed = $match[1] . ':/' . $match[2];
        }
        $this->assertSame(realpath($cwd), realpath($printed));
    }

    public function testIsReadonly(): void
    {
        $this->assertFalse($this->make(ExecToolkit::class)->isReadonly('echo', []));
    }

    public function testCallAllowsRegisteredUnderscoredToolName(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('CreateProcess does not run an extensionless shell script.');
        }

        $dir = sys_get_temp_dir() . '/switon-exec-odd-' . uniqid('', true);
        mkdir($dir);
        $bin = $dir . '/odd_bin';
        file_put_contents($bin, "#!/bin/sh\necho ok\n");
        chmod($bin, 0755);

        $prev = getenv('PATH') ?: '';
        putenv('PATH=' . $dir . PATH_SEPARATOR . $prev);
        try {
            $tool = $this->make(ExecToolkit::class, ['commands' => ['odd_bin']]);
            $payload = $this->callExec($tool, 'odd_bin', ['argv' => []]);
        } finally {
            putenv('PATH=' . $prev);
            @unlink($bin);
            @rmdir($dir);
        }

        $this->assertSame(0, $payload['exit']);
        $this->assertSame("ok\n", $payload['stdout']);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function callExec(ExecToolkit $tool, string $name, array $arguments): mixed
    {
        $tool->schema($name);

        return $this->callTool($tool, $name, $arguments);
    }

}
