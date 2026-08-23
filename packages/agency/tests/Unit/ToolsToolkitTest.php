<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\Tests\ToolsTestCase;
use Switon\Tooling\Tools;
use Switon\Ai\ToolkitInterface;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;

#[CoversClass(Tools::class)]
class ToolsToolkitTest extends ToolsTestCase
{
    public function testAddToolkitExposeAndCall(): void
    {
        $toolkit = new class () implements ToolkitInterface {
            public function schema(string $name): ?ToolSchema
            {
                if ($name === 'site_zhihu') {
                    return new ToolSchema($this, description: 'Zhihu search', properties: [
                        'query' => ['type' => 'string'],
                    ], required: ['query']);
                }
                if ($name === 'site_github') {
                    return new ToolSchema($this, description: 'GitHub search', properties: [
                        'query' => ['type' => 'string'],
                        'type' => ['type' => 'string', 'enum' => ['repositories', 'code']],
                    ], required: ['query']);
                }

                return null;
            }

            public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
            {
                return ['site' => $call->name, 'query' => $arguments['query'] ?? ''];
            }

            public function reload(): void
            {
            }

            /**
             * @param array<string, mixed> $arguments
             */
            public function isReadonly(string $name, array $arguments): bool
            {
                unset($name, $arguments);

                return false;
            }
        };

        $tools = $this->make(Tools::class);
        $tools->addToolkit('site', $toolkit);

        $github = $tools->pick(['site_github'])['site_github'];
        $zhihu = $tools->pick(['site_zhihu'])['site_zhihu'];
        $this->assertArrayHasKey('type', $github->definition['inputSchema']['properties']);
        $this->assertArrayNotHasKey('type', $zhihu->definition['inputSchema']['properties']);

        $exposed = $tools->pick(['site_zhihu']);
        $this->assertArrayHasKey('site_zhihu', $exposed);

        $result = $this->toolPayload($this->callStandalone($tools, 'site_zhihu', [
            'query' => '茅台',
        ]));
        $this->assertSame(['site' => 'site_zhihu', 'query' => '茅台'], $result);
    }
}
