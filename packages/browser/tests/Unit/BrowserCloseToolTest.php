<?php

declare(strict_types=1);

namespace Switon\Browser\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Browser\Exception as BrowserException;
use Switon\Browser\HeadedBrowsersInterface;
use Switon\Browser\Tool\BrowserCloseTool;
use Switon\Testing\TestCase;
use Switon\Tooling\Exception;

#[CoversClass(BrowserCloseTool::class)]
class BrowserCloseToolTest extends TestCase
{
    #[Test]
    public function callClosesTheNamedTab(): void
    {
        $agent = $this->createStub(AgentInterface::class);
        $browsers = $this->createMock(HeadedBrowsersInterface::class);
        $browsers->expects($this->once())
            ->method('close')
            ->with($agent, ' docs ')
            ->willReturn('docs');
        $tool = $this->make(BrowserCloseTool::class, ['browsers' => $browsers]);

        $result = $tool->call(
            $agent,
            new ToolCall(id: 'c1', name: 'browser_close'),
            ['tab' => ' docs '],
        );

        $this->assertSame(['closed' => 'docs'], $result, 'the reported id is the one that was stored, not the raw argument');
    }

    #[Test]
    public function schemaRequiresATab(): void
    {
        $schema = (new BrowserCloseTool())->schema('browser_close');

        $properties = $schema->definition['inputSchema']['properties'] ?? [];

        $this->assertSame(['tab'], $schema->definition['inputSchema']['required'] ?? []);
        $this->assertSame(1, $properties['tab']['minLength'] ?? null);
    }

    #[Test]
    public function callMapsABrowserFailure(): void
    {
        $browsers = $this->createStub(HeadedBrowsersInterface::class);
        $browsers->method('close')->willReturnCallback(static function (): never {
            BrowserException::raise('Tab "{tab}" is not open.', ['tab' => 'docs']);
        });
        $tool = $this->make(BrowserCloseTool::class, ['browsers' => $browsers]);

        $this->expectException(Exception::class);
        $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 'c1', name: 'browser_close'),
            ['tab' => 'docs'],
        );
    }
}
