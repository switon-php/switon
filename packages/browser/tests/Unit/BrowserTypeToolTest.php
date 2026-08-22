<?php

declare(strict_types=1);

namespace Switon\Browser\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Browser\Exception as BrowserException;
use Switon\Browser\HeadedBrowserInterface;
use Switon\Browser\Tool\BrowserTypeTool;
use Switon\Testing\TestCase;
use Switon\Tooling\Exception;

#[CoversClass(BrowserTypeTool::class)]
class BrowserTypeToolTest extends TestCase
{
    use HeadedBrowsersStub;
    #[Test]
    public function callTypesIntoTheRefAndCanSubmit(): void
    {
        $seen = [];
        $browser = $this->createStub(HeadedBrowserInterface::class);
        $browser->method('type')->willReturnCallback(
            static function (string $ref, string $text, bool $submit) use (&$seen): void {
                $seen = [$ref, $text, $submit];
            },
        );
        $browser->method('snapshot')->willReturn('typed');
        $tool = $this->make(BrowserTypeTool::class, ['browsers' => $this->browsers($browser)]);

        $result = $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 't1', name: 'browser_type'),
            ['tab' => 'main', 'ref' => 'e3', 'text' => 'Ada', 'submit' => true],
        );

        $this->assertSame(['e3', 'Ada', true], $seen);
        $this->assertSame(['snapshot' => 'typed'], $result);
    }

    #[Test]
    public function schemaRequiresARef(): void
    {
        $schema = (new BrowserTypeTool())->schema('browser_type');
        $properties = $schema->definition['inputSchema']['properties'] ?? [];

        $this->assertSame(['tab', 'ref', 'text'], $schema->definition['inputSchema']['required'] ?? []);
        $this->assertSame(1, $properties['tab']['minLength'] ?? null);
        $this->assertSame(1, $properties['ref']['minLength'] ?? null);
    }

    #[Test]
    public function callMapsABrowserFailure(): void
    {
        $browser = $this->createStub(HeadedBrowserInterface::class);
        $browser->method('type')->willReturnCallback(static function (): never {
            BrowserException::raise('stale ref');
        });
        $tool = $this->make(BrowserTypeTool::class, ['browsers' => $this->browsers($browser)]);

        $this->expectException(Exception::class);
        $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 't1', name: 'browser_type'),
            ['tab' => 'main', 'ref' => 'e1', 'text' => 'Ada'],
        );
    }
}
