<?php

declare(strict_types=1);

namespace Switon\Browser\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Browser\Exception as BrowserException;
use Switon\Browser\HeadedBrowserInterface;
use Switon\Browser\Tool\BrowserSnapshotTool;
use Switon\Testing\TestCase;
use Switon\Tooling\Exception;

#[CoversClass(BrowserSnapshotTool::class)]
class BrowserSnapshotToolTest extends TestCase
{
    use HeadedBrowsersStub;
    #[Test]
    public function callReturnsTheCurrentSnapshot(): void
    {
        $browser = $this->createStub(HeadedBrowserInterface::class);
        $browser->method('snapshot')->willReturn("[e1] button \"Go\"");
        $tool = $this->make(BrowserSnapshotTool::class, ['browsers' => $this->browsers($browser)]);

        $this->assertTrue($tool->isReadonly('browser_snapshot', []));
        $result = $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 's1', name: 'browser_snapshot'),
            ['tab' => 'main'],
        );

        $this->assertSame(['snapshot' => "[e1] button \"Go\""], $result);
    }

    #[Test]
    public function schemaRequiresATab(): void
    {
        $schema = (new BrowserSnapshotTool())->schema('browser_snapshot');

        $this->assertSame(['tab'], $schema->definition['inputSchema']['required'] ?? []);
        $this->assertSame(1, $schema->definition['inputSchema']['properties']['tab']['minLength'] ?? null);
    }

    #[Test]
    public function callMapsABrowserFailure(): void
    {
        $browser = $this->createStub(HeadedBrowserInterface::class);
        $browser->method('snapshot')->willReturnCallback(static function (): never {
            BrowserException::raise('no page');
        });
        $tool = $this->make(BrowserSnapshotTool::class, ['browsers' => $this->browsers($browser)]);

        $this->expectException(Exception::class);
        $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 's1', name: 'browser_snapshot'),
            ['tab' => 'main'],
        );
    }
}
