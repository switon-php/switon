<?php

declare(strict_types=1);

namespace Switon\Browser\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Browser\Exception as BrowserException;
use Switon\Browser\HeadedBrowserInterface;
use Switon\Browser\Tool\BrowserScrollTool;
use Switon\Testing\TestCase;
use Switon\Tooling\Exception;

#[CoversClass(BrowserScrollTool::class)]
class BrowserScrollToolTest extends TestCase
{
    use HeadedBrowsersStub;
    #[Test]
    public function callScrollsByRefOrByPixels(): void
    {
        $seen = [];
        $browser = $this->createStub(HeadedBrowserInterface::class);
        $browser->method('scroll')->willReturnCallback(
            static function (string $ref, int $y) use (&$seen): void {
                $seen = [$ref, $y];
            },
        );
        $browser->method('snapshot')->willReturn('scrolled');
        $tool = $this->make(BrowserScrollTool::class, ['browsers' => $this->browsers($browser)]);

        $byRef = $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 's1', name: 'browser_scroll'),
            ['tab' => 'main', 'ref' => 'e12'],
        );
        $this->assertSame(['e12', 0], $seen);
        $this->assertSame(['snapshot' => 'scrolled'], $byRef);

        $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 's2', name: 'browser_scroll'),
            ['tab' => 'main', 'y' => 400],
        );
        $this->assertSame(['', 400], $seen);
    }

    #[Test]
    public function schemaHasOptionalRefAndY(): void
    {
        $schema = (new BrowserScrollTool())->schema('browser_scroll');

        $this->assertArrayHasKey('ref', $schema->definition['inputSchema']['properties']);
        $this->assertArrayHasKey('y', $schema->definition['inputSchema']['properties']);
        $this->assertSame(['tab'], $schema->definition['inputSchema']['required'] ?? []);
        $this->assertSame(1, $schema->definition['inputSchema']['properties']['tab']['minLength'] ?? null);
    }

    #[Test]
    public function callMapsABrowserFailure(): void
    {
        $browser = $this->createStub(HeadedBrowserInterface::class);
        $browser->method('scroll')->willReturnCallback(static function (): never {
            BrowserException::raise('unknown ref');
        });
        $tool = $this->make(BrowserScrollTool::class, ['browsers' => $this->browsers($browser)]);

        $this->expectException(Exception::class);
        $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 's1', name: 'browser_scroll'),
            ['tab' => 'main', 'ref' => 'e99'],
        );
    }
}
