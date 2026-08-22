<?php

declare(strict_types=1);

namespace Switon\Search\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Search\WebFetchInterface;
use Switon\Tooling\AbstractTool;

/** Tool `web_fetch` over {@see WebFetchInterface}. */
class WebFetchTool extends AbstractTool
{
    #[Autowired] protected WebFetchInterface $webFetch;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Fetch a URL and return readable page text or the raw response body.',
            properties: [
                'url' => [
                    'type' => 'string',
                    'description' => 'Absolute URL.',
                ],
                'raw' => [
                    'type' => 'boolean',
                    'description' => 'Raw body (skip HTML→markdown).',
                    'default' => false,
                ],
            ],
            required: ['url'],
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $payload = $this->webFetch->fetch(
            $arguments['url'],
            fn (): bool => $agent->isCancelled() || $call->cancelled,
            $arguments['raw'] ?? false,
        );

        if (is_string($payload['content'] ?? null)) {
            $payload['content'] = $this->spillField($payload['content'], $call->id, $agent, 'content');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        return true;
    }

}
