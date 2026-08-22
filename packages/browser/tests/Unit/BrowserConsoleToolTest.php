<?php

declare(strict_types=1);

namespace Switon\Browser\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Browser\Exception as BrowserException;
use Switon\Browser\HeadedBrowserInterface;
use Switon\Browser\Tool\BrowserConsoleTool;
use Switon\Testing\TestCase;
use Switon\Tooling\Exception;

/**
 * `browser_console` turns diagnostics into something an agent can read and filter.
 */
#[CoversClass(BrowserConsoleTool::class)]
class BrowserConsoleToolTest extends TestCase
{
    use HeadedBrowsersStub;
    #[Test]
    public function schemaFiltersByLevelWithADefault(): void
    {
        $tool = new BrowserConsoleTool();
        $schema = $tool->schema('browser_console');

        $this->assertArrayHasKey('level', $schema->definition['inputSchema']['properties']);
        $this->assertSame('error', $schema->definition['inputSchema']['properties']['level']['default'] ?? null);
        $this->assertSame(['tab'], $schema->definition['inputSchema']['required'] ?? []);
        $this->assertSame(1, $schema->definition['inputSchema']['properties']['tab']['minLength'] ?? null);
        $this->assertTrue($tool->isReadonly('browser_console', []));
    }

    #[Test]
    public function callKeepsErrorsAndDropsChattyLinesByDefault(): void
    {
        $result = $this->call($this->entries(), []);

        $this->assertSame(3, $result['count'], 'a console log and an info line are below the error floor');
        $this->assertStringContainsString('fatal: config missing', $result['text']);
        $this->assertStringContainsString('BOOM', $result['text']);
        $this->assertStringContainsString('404', $result['text']);
    }

    #[Test]
    public function callReturnsEverythingWhenAskedForAllLevels(): void
    {
        $result = $this->call($this->entries(), ['level' => 'all']);

        $this->assertSame(5, $result['count']);
        $this->assertStringContainsString('boot: starting', $result['text']);
    }

    #[Test]
    public function callKeepsTheMostRecentEntriesWhenLimited(): void
    {
        $result = $this->call($this->entries(), ['level' => 'all', 'limit' => 2]);

        $this->assertSame(2, $result['count']);
        $this->assertStringContainsString('BOOM', $result['text']);
        $this->assertStringNotContainsString('boot: starting', $result['text']);
    }

    #[Test]
    public function callRestoresTheDefaultLimitWhenZeroIsPassed(): void
    {
        $result = $this->call($this->entries(), ['level' => 'all', 'limit' => 0]);

        $this->assertSame(5, $result['count']);
    }

    #[Test]
    public function callSaysSoWhenNothingWasLogged(): void
    {
        $result = $this->call([], []);

        $this->assertSame(0, $result['count']);
        $this->assertSame('(no console output at this level)', $result['text']);
    }

    #[Test]
    public function callMapsABrowserFailure(): void
    {
        $browser = $this->createStub(HeadedBrowserInterface::class);
        $browser->method('diagnostics')->willReturnCallback(static function (): never {
            BrowserException::raise('no page');
        });
        $tool = $this->make(BrowserConsoleTool::class, ['browsers' => $this->browsers($browser)]);

        $this->expectException(Exception::class);
        $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 'call_console', name: 'browser_console'),
            ['tab' => 'main'],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function call(array $entries, array $arguments): array
    {
        $browser = $this->createStub(HeadedBrowserInterface::class);
        $browser->method('diagnostics')->willReturn($entries);
        $tool = $this->make(BrowserConsoleTool::class, ['browsers' => $this->browsers($browser)]);

        $result = $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 'call_console', name: 'browser_console'),
            ['tab' => 'main'] + $arguments,
        );

        $this->assertIsArray($result);

        return $result;
    }

    /**
     * @return list<array{type: string, level: string, text: string, url: string, line: int}>
     */
    private function entries(): array
    {
        return [
            ['type' => 'console', 'level' => 'log', 'text' => 'boot: starting', 'url' => 'http://x/', 'line' => 3],
            ['type' => 'console', 'level' => 'error', 'text' => 'fatal: config missing', 'url' => 'http://x/', 'line' => 4],
            ['type' => 'response', 'level' => 'error', 'text' => '404 Not Found', 'url' => 'http://x/api', 'line' => 0],
            ['type' => 'log', 'level' => 'info', 'text' => 'deprecation notice', 'url' => '', 'line' => 0],
            ['type' => 'exception', 'level' => 'error', 'text' => 'BOOM: undefined is not a function', 'url' => 'http://x/', 'line' => 9],
        ];
    }
}
