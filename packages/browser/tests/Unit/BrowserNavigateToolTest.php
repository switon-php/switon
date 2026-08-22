<?php

declare(strict_types=1);

namespace Switon\Browser\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Browser\Exception as BrowserException;
use Switon\Browser\HeadedBrowserInterface;
use Switon\Browser\Tool\BrowserNavigateTool;
use Switon\Testing\TestCase;
use Switon\Tooling\Exception;

#[CoversClass(BrowserNavigateTool::class)]
class BrowserNavigateToolTest extends TestCase
{
    use HeadedBrowsersStub;
    #[Test]
    public function testCallReturnsTheSnapshot(): void
    {
        $browser = $this->createMock(HeadedBrowserInterface::class);
        $browser->expects($this->once())
            ->method('navigate')
            ->with('https://example.com/', $this->anything(), 'h1');
        $browser->method('snapshot')->willReturn("https://example.com/\nheading \"Example\"");
        $tool = $this->make(BrowserNavigateTool::class, ['browsers' => $this->browsers($browser)]);

        $result = $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 'call_nav', name: 'browser_navigate'),
            ['tab' => 'main', 'url' => 'https://example.com/', 'ready' => 'h1'],
        );

        $this->assertSame(['snapshot' => "https://example.com/\nheading \"Example\""], $result);
    }

    #[Test]
    public function schemaRequiresAUrl(): void
    {
        $schema = (new BrowserNavigateTool())->schema('browser_navigate');

        $properties = $schema->definition['inputSchema']['properties'] ?? [];

        $this->assertSame(['tab', 'url'], $schema->definition['inputSchema']['required'] ?? []);
        $this->assertSame(1, $properties['tab']['minLength'] ?? null);
        $this->assertSame(1, $properties['url']['minLength'] ?? null);
    }

    #[Test]
    public function callMapsABrowserFailure(): void
    {
        $browser = $this->createStub(HeadedBrowserInterface::class);
        $browser->method('navigate')->willReturnCallback(static function (): never {
            BrowserException::raise('chrome refused');
        });
        $tool = $this->make(BrowserNavigateTool::class, ['browsers' => $this->browsers($browser)]);

        $this->expectException(Exception::class);
        $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 'call_nav', name: 'browser_navigate'),
            ['tab' => 'main', 'url' => 'https://example.com/'],
        );
    }
}
