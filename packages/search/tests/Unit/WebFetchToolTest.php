<?php

declare(strict_types=1);

namespace Switon\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\ToolCall;
use Switon\Search\Tests\Fixtures\StubAgent;
use Switon\Search\Tests\ToolTestCase;
use Switon\Search\Tool\WebFetchTool;
use Switon\Search\WebFetchInterface;

#[CoversClass(WebFetchTool::class)]
class WebFetchToolTest extends ToolTestCase
{
    public function testDroppableIsTrue(): void
    {
        $this->assertTrue($this->make(WebFetchTool::class)->isReadonly('web_fetch', ['url' => 'https://example.com']));
    }

    public function testSchemaIsShort(): void
    {
        $schema = $this->make(WebFetchTool::class)->schema('web_fetch');

        $this->assertSame('Fetch a URL and return readable page text or the raw response body.', $schema->definition['description']);
        $this->assertSame(['url'], $schema->definition['inputSchema']['required']);
        $this->assertSame('Absolute URL.', $schema->definition['inputSchema']['properties']['url']['description']);
        $this->assertSame('Raw body (skip HTML→markdown).', $schema->definition['inputSchema']['properties']['raw']['description']);
        $this->assertFalse($schema->definition['inputSchema']['properties']['raw']['default']);
        $this->assertArrayNotHasKey('explanation', $schema->definition['inputSchema']['properties']);
    }

    public function testCallDelegatesToWebFetch(): void
    {
        $stub = new class () implements WebFetchInterface {
            public string $lastUrl = '';
            public bool $lastRaw = false;

            public function fetch(string $url, callable $shouldAbort, bool $raw = false): array
            {
                $this->lastUrl = $url;
                $this->lastRaw = $raw;

                return [
                    'url' => $url,
                    'status_code' => 200,
                    'content_type' => 'text/html',
                    'title' => 'T',
                    'format' => 'markdown',
                    'content' => 'body',
                ];
            }
        };

        $this->container->set(WebFetchInterface::class, $stub);
        $tool = $this->make(WebFetchTool::class);

        $payload = $tool->call(
            StubAgent::in(),
            new ToolCall(name: 'web_fetch', arguments: ['url' => 'https://example.com/x', 'raw' => true]),
            ['url' => 'https://example.com/x', 'raw' => true],
        );

        $this->assertSame('https://example.com/x', $stub->lastUrl);
        $this->assertTrue($stub->lastRaw);
        $this->assertSame('body', $payload['content']);
    }
}
