<?php

declare(strict_types=1);

namespace Switon\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Search\Exception;
use Switon\Search\Tests\Fixtures\StubWebSearch;
use Switon\Search\Tests\ToolTestCase;
use Switon\Search\Tool\WebSearchTool;
use Switon\Search\WebSearchInterface;

#[CoversClass(WebSearchTool::class)]
class WebSearchToolTest extends ToolTestCase
{
    public function testDroppableIsTrue(): void
    {
        $this->assertTrue($this->makeTool()->isReadonly('web_search', ['query' => 'x']));
    }

    public function testSchemaIncludesWebSearch(): void
    {
        $tool = $this->makeTool();

        $schema = $tool->schema('web_search');
        $this->assertNotNull($schema);
        $this->assertSame('Search the web.', $schema->definition['description']);
        $this->assertSame(['query'], $schema->definition['inputSchema']['required']);
        $this->assertSame('Search query.', $schema->definition['inputSchema']['properties']['query']['description']);
        $this->assertSame(
            'Optional hostname (e.g. github.com).',
            $schema->definition['inputSchema']['properties']['site']['description'],
        );
        $this->assertSame(
            'Optional language hint (e.g. zh-CN, en).',
            $schema->definition['inputSchema']['properties']['lang']['description'],
        );
        $this->assertArrayNotHasKey('max_results', $schema->definition['inputSchema']['properties']);
        $this->assertArrayNotHasKey('explanation', $schema->definition['inputSchema']['properties']);
    }

    public function testDisabledToolOmitsSchema(): void
    {
        $tool = $this->make(WebSearchTool::class, ['enabled' => false]);

        $this->assertNull($tool->schema('web_search'));
    }

    public function testCallReturnsBackendResults(): void
    {
        $tool = $this->makeTool();

        $payload = $this->callTool($tool, 'web_search', ['query' => 'switon framework']);

        $this->assertSame('switon framework', $payload['query']);
        $this->assertSame(['stub'], $payload['engines']);
        $this->assertCount(1, $payload['results']);
        $this->assertSame('https://example.com/docs', $payload['results'][0]['url']);
    }

    public function testSitePassesNormalizedHost(): void
    {
        $stub = new StubWebSearch();
        $this->container->set(WebSearchInterface::class, $stub);
        $tool = $this->make(WebSearchTool::class);

        $payload = $this->callTool($tool, 'web_search', [
            'query' => 'agent tools',
            'site' => 'https://github.com/switon-php',
        ]);

        $this->assertSame('agent tools site:github.com', $stub->lastQuery);
        $this->assertSame('github.com', $stub->lastSite);
        $this->assertSame('agent tools', $payload['query']);
        $this->assertSame('github.com', $payload['site']);
    }

    public function testSiteDoesNotDuplicateExistingFilter(): void
    {
        $stub = new StubWebSearch();
        $this->container->set(WebSearchInterface::class, $stub);
        $tool = $this->make(WebSearchTool::class);

        $this->callTool($tool, 'web_search', [
            'query' => 'foo site:github.com',
            'site' => 'github.com',
        ]);

        $this->assertSame('foo site:github.com', $stub->lastQuery);
    }

    public function testLangIsForwarded(): void
    {
        $stub = new StubWebSearch();
        $this->container->set(WebSearchInterface::class, $stub);
        $tool = $this->make(WebSearchTool::class);

        $payload = $this->callTool($tool, 'web_search', [
            'query' => 'swoole',
            'lang' => 'zh-CN',
        ]);

        $this->assertSame('zh-CN', $stub->lastLang);
        $this->assertSame('zh-CN', $payload['lang']);
    }

    public function testInvalidSiteRaises(): void
    {
        $tool = $this->makeTool();

        $this->expectException(Exception::class);
        $this->callTool($tool, 'web_search', ['query' => 'x', 'site' => 'not a host']);
    }

    public function testTopicWordSiteRaises(): void
    {
        $tool = $this->makeTool();

        $this->expectException(Exception::class);
        $this->callTool($tool, 'web_search', ['query' => '深圳天气', 'site' => 'weather']);
    }

    public function testIgnoresCallerMaxResultsArgument(): void
    {
        $stub = new StubWebSearch();
        $stub->results = [
            ['title' => 'A', 'url' => 'https://a.example', 'snippet' => 'a', 'engine' => 'stub'],
            ['title' => 'B', 'url' => 'https://b.example', 'snippet' => 'b', 'engine' => 'stub'],
            ['title' => 'C', 'url' => 'https://c.example', 'snippet' => 'c', 'engine' => 'stub'],
        ];
        $this->container->set(WebSearchInterface::class, $stub);
        $tool = $this->make(WebSearchTool::class);

        // Legacy/hallucinated max_results must not shrink the host ceiling.
        $payload = $this->callTool($tool, 'web_search', ['query' => 'x', 'max_results' => 1]);
        $this->assertCount(3, $payload['results']);
        $this->assertSame(60, $stub->lastMaxResults);
    }

    public function testEmptyResultsAddsWarning(): void
    {
        $stub = new StubWebSearch();
        $stub->results = [];
        $this->container->set(WebSearchInterface::class, $stub);
        $tool = $this->make(WebSearchTool::class);

        $payload = $this->callTool($tool, 'web_search', ['query' => 'nothing']);

        $this->assertSame([], $payload['results']);
        $this->assertArrayNotHasKey('engines', $payload);
        $this->assertArrayHasKey('warning', $payload);
    }

    protected function makeTool(): WebSearchTool
    {
        $this->container->set(WebSearchInterface::class, StubWebSearch::class);

        return $this->make(WebSearchTool::class);
    }
}
