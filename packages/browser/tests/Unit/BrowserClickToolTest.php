<?php

declare(strict_types=1);

namespace Switon\Browser\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Browser\Exception as BrowserException;
use Switon\Browser\HeadedBrowserInterface;
use Switon\Browser\Tool\BrowserClickTool;
use Switon\Testing\TestCase;
use Switon\Tooling\Exception;

#[CoversClass(BrowserClickTool::class)]
class BrowserClickToolTest extends TestCase
{
    use HeadedBrowsersStub;

    #[Test]
    public function callClicksTheRefAndReturnsAFreshSnapshot(): void
    {
        $clicked = '';
        $browser = $this->createStub(HeadedBrowserInterface::class);
        $browser->method('click')->willReturnCallback(static function (string $ref) use (&$clicked): void {
            $clicked = $ref;
        });
        $browser->method('snapshot')->willReturn('[e1] button "Go"');
        $tool = $this->make(BrowserClickTool::class, ['browsers' => $this->browsers($browser)]);

        $result = $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 'c1', name: 'browser_click'),
            ['tab' => 'main', 'ref' => 'e1'],
        );

        $this->assertSame('e1', $clicked);
        $this->assertSame(['snapshot' => '[e1] button "Go"'], $result);
    }

    #[Test]
    public function schemaRequiresARef(): void
    {
        $schema = (new BrowserClickTool())->schema('browser_click');
        $properties = $schema->definition['inputSchema']['properties'] ?? [];

        $this->assertSame(['tab', 'ref'], $schema->definition['inputSchema']['required'] ?? []);
        $this->assertSame(1, $properties['tab']['minLength'] ?? null);
        $this->assertSame(1, $properties['ref']['minLength'] ?? null);
    }

    #[Test]
    public function callMapsABrowserFailure(): void
    {
        $browser = $this->createStub(HeadedBrowserInterface::class);
        $browser->method('click')->willReturnCallback(static function (): never {
            BrowserException::raise('stale ref');
        });
        $tool = $this->make(BrowserClickTool::class, ['browsers' => $this->browsers($browser)]);

        $this->expectException(Exception::class);
        $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 'c1', name: 'browser_click'),
            ['tab' => 'main', 'ref' => 'e1'],
        );
    }
}
