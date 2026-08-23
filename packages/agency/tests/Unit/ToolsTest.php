<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use ArrayIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\Tests\Fixtures\DisabledTool;
use Switon\Agency\Tests\Fixtures\StubTool;
use Switon\Agency\Tests\ToolsTestCase;
use Switon\Tooling\ExecToolkit;
use Switon\Tooling\Tools;
use Switon\Tooling\ToolsInterface;
use Switon\Ai\ToolSchema;

#[CoversClass(Tools::class)]
class ToolsTest extends ToolsTestCase
{
    public function testExposeSkipsDisabled(): void
    {
        $tools = $this->makeTools([
            'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'pong'),
            'off' => DisabledTool::class,
        ]);
        $this->assertSame(['echo'], array_keys($tools->pick(['echo'])));
        $this->assertSame([], array_keys($tools->pick(['off'])));
    }

    public function testExposeReturnsRequestedTool(): void
    {
        $tools = $this->makeTools([
            'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'pong'),
        ]);
        $this->assertSame(['echo'], array_keys($tools->pick(['echo'])));
    }

    public function testExposeFiltersByExactName(): void
    {
        $tools = $this->makeTools([
            'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'pong'),
            'read' => StubTool::config('read', 'Read', static fn (): string => 'data'),
        ]);

        $this->assertSame(['echo'], array_keys($tools->pick(['echo'])));
    }

    public function testExposeOmitsUnknownNames(): void
    {
        $tools = $this->makeTools([
            'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'pong'),
        ]);

        $this->assertSame(['echo'], array_keys($tools->pick(['echo', '*', 'file_*'])));
    }

    public function testExposeWithEmptyToolsReturnsNoTools(): void
    {
        $tools = $this->makeTools([
            'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'pong'),
        ]);

        $this->assertSame([], $tools->pick([]));
    }

    public function testCallKeepsDotCaptionInArguments(): void
    {
        $tools = $this->makeTools(['echo' => StubTool::config('echo', 'Echo')]);

        $result = $this->callStandalone($tools, 'echo', ['x' => 1, '.caption' => 'Running echo.']);

        $this->assertSame(['x' => 1, '.caption' => 'Running echo.'], $this->toolPayload($result));
    }

    public function testCallRoutesToTool(): void
    {
        $tools = $this->makeTools([
            'echo' => StubTool::config('echo', 'Echo', static fn (): string => 'pong'),
        ]);

        $this->assertSame('pong', $this->toolPayload($this->callStandalone($tools, 'echo', [])));
    }

    public function testCallRoutesBareNameToExec(): void
    {
        $tools = $this->make(Tools::class, ['tools' => new ArrayIterator([])]);
        $tools->pick(['echo']);

        $payload = $this->toolPayload($this->callStandalone($tools, 'echo', ['argv' => ['hi']]));

        $this->assertSame(0, $payload['exit']);
        $this->assertSame("hi\n", $payload['stdout']);
    }

    public function testExposeBuildsShellSchemaForExplicitCommand(): void
    {
        $tools = $this->makeTools();

        $exposed = $tools->pick(['git']);

        $this->assertArrayHasKey('git', $exposed);
        $this->assertStringContainsString('Unlike `bash`', $exposed['git']->definition['description']);
    }

    public function testCallRoutesToShellClient(): void
    {
        $tools = $this->makeTools();
        $tools->pick(['echo']);

        $payload = $this->toolPayload($this->callStandalone($tools, 'echo', ['argv' => ['hello']]));

        $this->assertSame(0, $payload['exit']);
        $this->assertSame("hello\n", $payload['stdout']);
    }

    /** @param array<string, mixed> $tools */
    protected function makeTools(array $tools = []): ToolsInterface
    {
        return $this->make(Tools::class, [
            'tools' => new ArrayIterator($tools),
            'toolkits' => new ArrayIterator(['exec' => ExecToolkit::class]),
        ]);
    }
}
