<?php

declare(strict_types=1);

namespace Switon\Browser\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Switon\Ai\AgentInterface;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Browser\Exception as BrowserException;
use Switon\Browser\HeadedBrowserInterface;
use Switon\Browser\Tool\BrowserTakeScreenshotTool;
use Switon\Testing\TestCase;
use Switon\Tooling\Exception;

#[CoversClass(BrowserTakeScreenshotTool::class)]
class BrowserTakeScreenshotToolTest extends TestCase
{
    use HeadedBrowsersStub;
    #[Test]
    public function testSchemaHasFullPageOptional(): void
    {
        $tool = new BrowserTakeScreenshotTool();
        $schema = $tool->schema('browser_take_screenshot');

        $this->assertArrayHasKey('fullPage', $schema->definition['inputSchema']['properties']);
        $this->assertSame(['tab'], $schema->definition['inputSchema']['required'] ?? []);
        $this->assertSame(1, $schema->definition['inputSchema']['properties']['tab']['minLength'] ?? null);
        $this->assertTrue($tool->isReadonly('browser_take_screenshot', []));
    }

    #[Test]
    public function testCallAttachesThePng(): void
    {
        $browser = $this->createStub(HeadedBrowserInterface::class);
        $browser->method('takeScreenshot')->willReturn([
            'path' => '/tmp/shot.png',
            'mime' => 'image/png',
            'bytes' => 12,
            'url' => 'https://example.com/',
        ]);
        $tool = $this->make(BrowserTakeScreenshotTool::class, ['browsers' => $this->browsers($browser)]);

        $result = $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 'call_shot', name: 'browser_take_screenshot'),
            ['tab' => 'main'],
        );

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertSame(['url' => 'https://example.com/', 'path' => '/tmp/shot.png'], $result->content);
        $this->assertInstanceOf(MediaPart::class, $result->parts[0]);
        $this->assertSame('/tmp/shot.png', $result->parts[0]->ref);
        $this->assertSame('image/png', $result->parts[0]->mime);
    }

    #[Test]
    public function callMapsABrowserFailure(): void
    {
        $browser = $this->createStub(HeadedBrowserInterface::class);
        $browser->method('takeScreenshot')->willReturnCallback(static function (): never {
            BrowserException::raise('write failed');
        });
        $tool = $this->make(BrowserTakeScreenshotTool::class, ['browsers' => $this->browsers($browser)]);

        $this->expectException(Exception::class);
        $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 'call_shot', name: 'browser_take_screenshot'),
            ['tab' => 'main', 'fullPage' => true],
        );
    }
}
