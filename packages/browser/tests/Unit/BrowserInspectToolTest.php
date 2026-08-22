<?php

declare(strict_types=1);

namespace Switon\Browser\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Browser\Exception as BrowserException;
use Switon\Browser\HeadedBrowserInterface;
use Switon\Browser\Tool\BrowserInspectTool;
use Switon\Testing\TestCase;

/**
 * `browser_inspect` reports computed styles, so an agent can explain "invisible" or "wrong colour".
 */
#[CoversClass(BrowserInspectTool::class)]
class BrowserInspectToolTest extends TestCase
{
    use HeadedBrowsersStub;
    #[Test]
    public function schemaRequiresARef(): void
    {
        $tool = new BrowserInspectTool();
        $schema = $tool->schema('browser_inspect');

        $properties = $schema->definition['inputSchema']['properties'] ?? [];

        $this->assertSame(['tab', 'ref'], $schema->definition['inputSchema']['required'] ?? []);
        $this->assertSame(1, $properties['tab']['minLength'] ?? null);
        $this->assertSame(1, $properties['ref']['minLength'] ?? null);
        $this->assertTrue($tool->isReadonly('browser_inspect', []));
    }

    #[Test]
    public function callPassesRefAndPropertiesToTheBrowser(): void
    {
        $seenRef = '';
        $seenProperties = [];
        $browser = $this->createStub(HeadedBrowserInterface::class);
        $browser->method('inspect')->willReturnCallback(
            static function (string $ref, array $properties) use (&$seenRef, &$seenProperties): array {
                $seenRef = $ref;
                $seenProperties = $properties;

                return ['target' => $ref, 'tag' => 'div', 'visible' => false, 'box' => [], 'styles' => ['display' => 'none']];
            },
        );
        $tool = $this->make(BrowserInspectTool::class, ['browsers' => $this->browsers($browser)]);

        $result = $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 'call_inspect', name: 'browser_inspect'),
            ['tab' => 'main', 'ref' => 'e7', 'properties' => ['display', ' opacity ', '']],
        );

        $this->assertSame('e7', $seenRef);
        $this->assertSame(['display', 'opacity'], $seenProperties, 'blank property names are dropped');
        $this->assertIsArray($result);
        $this->assertFalse($result['visible']);
    }

    #[Test]
    public function callReportsAnUnknownRefAsAToolFailure(): void
    {
        $browser = $this->createStub(HeadedBrowserInterface::class);
        $browser->method('inspect')->willReturnCallback(function (): array {
            throw $this->raiseBrowserException();
        });
        $tool = $this->make(BrowserInspectTool::class, ['browsers' => $this->browsers($browser)]);

        $this->expectException(\Switon\Tooling\Exception::class);
        $this->expectExceptionMessageMatches('/Unknown element ref/');
        $tool->call(
            $this->createStub(AgentInterface::class),
            new ToolCall(id: 'call_inspect', name: 'browser_inspect'),
            ['tab' => 'main', 'ref' => 'e99'],
        );
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
