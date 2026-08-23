<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use ArrayIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Tooling\ExecToolkit;
use Switon\Agency\Tests\Fixtures\DisabledTool;
use Switon\Agency\Tests\Fixtures\EchoTool;
use Switon\Agency\Tests\Fixtures\StubTool;
use Switon\Agency\Tests\ToolsTestCase;
use Switon\Tooling\Tools;
use RuntimeException;

#[CoversClass(Tools::class)]
class ToolsDispatchTest extends ToolsTestCase
{
    public function testCallReturnsResult(): void
    {
        $tools = $this->makeTools([
            'echo' => StubTool::config('echo', 'Echo input'),
        ]);

        $this->assertSame(
            ['text' => 'hi'],
            $this->toolPayload($this->callStandalone($tools, 'echo', ['text' => 'hi'])),
        );
    }

    public function testConfigRegistersTools(): void
    {
        $tools = $this->makeTools(['echo' => EchoTool::class]);

        $this->assertArrayHasKey('echo', $tools->pick(['echo']));

        $this->assertSame(
            ['ok' => true],
            $this->toolPayload($this->callStandalone($tools, 'echo', ['ok' => true])),
        );
    }

    public function testConfigUsesTool(): void
    {
        $tools = $this->makeTools(['echo' => EchoTool::class]);

        $this->assertSame('Echo tool arguments.', $tools->pick(['echo'])['echo']->definition['description']);
    }

    public function testNullSchemaOmitsToolFromRegistry(): void
    {
        $tools = $this->makeTools([
            'echo' => EchoTool::class,
            'off' => DisabledTool::class,
        ]);

        $this->assertArrayHasKey('echo', $tools->pick(['echo']));
        $this->assertArrayNotHasKey('off', $tools->pick(['off']));
    }

    public function testExposeInjectsCaptionWithoutCloning(): void
    {
        $tools = $this->makeTools([
            'quiet' => StubTool::config('quiet', 'Quiet'),
            'echo' => EchoTool::class,
        ]);

        $exposedEcho = $tools->pick(['echo'])['echo'];
        $exposedAgain = $tools->pick(['echo'])['echo'];
        $exposedShell = $tools->pick(['ls'])['ls'];

        $this->assertSame('Echo tool arguments.', $exposedEcho->definition['description']);
        $this->assertArrayHasKey('.caption', $exposedEcho->definition['inputSchema']['properties']);
        $this->assertArrayHasKey('.caption', $exposedAgain->definition['inputSchema']['properties']);
        $this->assertStringContainsString('Unlike `bash`', $exposedShell->definition['description']);
    }

    public function testCallThrowableReturnsErrorJson(): void
    {
        $tools = $this->makeTools([
            'boom' => StubTool::config('boom', 'Boom', static fn (): never => throw new RuntimeException('native down')),
        ]);

        $result = $this->callStandalone($tools, 'boom', []);

        $this->assertInstanceOf(RuntimeException::class, $result->content);
        $this->assertSame('native down', $result->content->getMessage());
    }

    public function testCallReturnsStringResultAsIs(): void
    {
        $tools = $this->makeTools([
            'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'pong'),
        ]);

        $this->assertSame('pong', $this->toolPayload($this->callStandalone($tools, 'echo', [])));
    }

    public function testCallRoutesToExecForUnregisteredShellCommand(): void
    {
        $tools = $this->makeTools();
        $tools->pick(['echo']);

        $payload = $this->toolPayload($this->callStandalone($tools, 'echo', ['argv' => ['hello']]));

        $this->assertSame(0, $payload['exit']);
        $this->assertSame("hello\n", $payload['stdout']);
    }

    public function testAllReturnsRegisteredTool(): void
    {
        $tools = $this->makeTools(['echo' => EchoTool::class]);

        $schema = $tools->pick(['echo'])['echo'] ?? null;

        $this->assertNotNull($schema);
        $this->assertSame('Echo tool arguments.', $schema->definition['description']);
    }

    public function testExposeReturnsExecSchemaForShellCommandName(): void
    {
        $tools = $this->makeTools();

        $schema = $tools->pick(['git'])['git'] ?? null;

        $this->assertNotNull($schema);
        $this->assertStringContainsString('Unlike `bash`', $schema->definition['description']);
    }

    public function testExposeOmitsUnknownMcpPrefixedName(): void
    {
        $this->assertArrayNotHasKey('app_time_now', $this->makeTools()->pick(['app_time_now']));
    }

    public function testExposeOmitsInvalidName(): void
    {
        $this->assertArrayNotHasKey('bad.name', $this->makeTools()->pick(['bad.name']));
    }

    public function testExposeOmitsNameOutsideAutoPattern(): void
    {
        $this->assertArrayNotHasKey('llvm_objdump', $this->makeTools()->pick(['llvm_objdump']));
    }

    public function testExposeClaimsRegisteredUnderscoredBinary(): void
    {
        $tools = $this->makeTools(execCommands: ['llvm_objdump']);

        $schema = $tools->pick(['llvm_objdump'])['llvm_objdump'] ?? null;

        $this->assertNotNull($schema);
        $this->assertStringContainsString('Unlike `bash`', $schema->definition['description']);
    }

    /**
     * @param array<string, mixed> $tools
     * @param list<string> $execCommands Underscored binaries the exec toolkit should claim
     */
    protected function makeTools(array $tools = [], array $execCommands = []): Tools
    {
        return $this->make(Tools::class, [
            'tools' => new ArrayIterator($tools),
            'toolkits' => new ArrayIterator([
                'exec' => ['class' => ExecToolkit::class, 'commands' => $execCommands],
            ]),
        ]);
    }
}
