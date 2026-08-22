<?php

declare(strict_types=1);

namespace Switon\Browser\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Browser\Exception as BrowserException;
use Switon\Browser\HeadedBrowserInterface;
use Switon\Browser\Tool\BrowserAssertTool;
use Switon\Testing\TestCase;

/**
 * `browser_assert` reports whether a condition held, without throwing on a normal failure.
 *
 * A failed assertion is information, not an error: the agent reads `ok` and `actual` and decides
 * what to do next.
 */
#[CoversClass(BrowserAssertTool::class)]
class BrowserAssertToolTest extends TestCase
{
    use HeadedBrowsersStub;
    #[Test]
    public function schemaRequiresARefAndDefaultsToVisible(): void
    {
        $tool = new BrowserAssertTool();
        $schema = $tool->schema('browser_assert');

        $properties = $schema->definition['inputSchema']['properties'] ?? [];

        $this->assertSame(['tab', 'ref'], $schema->definition['inputSchema']['required'] ?? []);
        $this->assertSame(1, $properties['tab']['minLength'] ?? null);
        $this->assertSame(1, $properties['ref']['minLength'] ?? null);
        $this->assertSame('visible', $properties['kind']['default'] ?? null);
        $this->assertTrue($tool->isReadonly('browser_assert', []));
    }

    #[Test]
    public function callReturnsTheResultWhenTheConditionHeld(): void
    {
        $result = $this->call(['ok' => true, 'actual' => 'visible', 'waitedMs' => 12]);

        $this->assertTrue($result['ok']);
        $this->assertSame('visible: visible', $result['text']);
    }

    #[Test]
    public function callExplainsWhatWasFoundWhenTheConditionFailed(): void
    {
        $result = $this->call(
            ['ok' => false, 'actual' => 'hidden', 'waitedMs' => 1000],
            ['kind' => 'visible'],
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('failed after 1000ms', $result['text']);
        $this->assertStringContainsString('found "hidden"', $result['text']);
    }

    #[Test]
    public function callPassesKindExpectedAndTimeoutThrough(): void
    {
        $seen = [];
        $browser = $this->createStub(HeadedBrowserInterface::class);
        $browser->method('assert')->willReturnCallback(
            static function (string $ref, string $kind, string $expected, float $timeout) use (&$seen): array {
                $seen = [$ref, $kind, $expected, $timeout];

                return ['ok' => true, 'actual' => 'hello', 'target' => '', 'kind' => $kind, 'expected' => $expected, 'waitedMs' => 5];
            },
        );
        $tool = $this->make(BrowserAssertTool::class, ['browsers' => $this->browsers($browser)]);

        $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 'call_assert', name: 'browser_assert'),
            ['tab' => 'main', 'ref' => 'e7', 'kind' => 'text', 'expected' => 'hello', 'timeout' => 2.5],
        );

        $this->assertSame(['e7', 'text', 'hello', 2.5], $seen);
    }

    #[Test]
    public function callReportsAnUnknownRefAsAToolFailure(): void
    {
        $browser = $this->createStub(HeadedBrowserInterface::class);
        $browser->method('assert')->willReturnCallback(function (): array {
            throw $this->raiseBrowserException();
        });
        $tool = $this->make(BrowserAssertTool::class, ['browsers' => $this->browsers($browser)]);

        $this->expectException(\Switon\Tooling\Exception::class);
        $this->expectExceptionMessageMatches('/Unknown element ref/');
        $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 'call_assert', name: 'browser_assert'),
            ['tab' => 'main', 'ref' => 'e99'],
        );
    }

    /**
     * @param array<string, mixed> $outcome What the browser reports
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function call(array $outcome, array $arguments = []): array
    {
        $browser = $this->createStub(HeadedBrowserInterface::class);
        $browser->method('assert')->willReturn($outcome + [
            'target' => '#7',
            'kind' => 'visible',
            'expected' => '',
            'waitedMs' => 0,
        ]);
        $tool = $this->make(BrowserAssertTool::class, ['browsers' => $this->browsers($browser)]);

        $result = $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 'call_assert', name: 'browser_assert'),
            ['tab' => 'main', 'ref' => 'e7'] + $arguments,
        );

        $this->assertIsArray($result);

        return $result;
    }

    /** Build the exception the way the package does: no public constructor. */
    private function raiseBrowserException(): BrowserException
    {
        try {
            BrowserException::raise('Unknown element ref "{ref}".', ['ref' => 'e99']);
        } catch (BrowserException $e) {
            return $e;
        }

        return $this->fail('BrowserException::raise() did not throw.');
    }
}
