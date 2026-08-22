<?php

declare(strict_types=1);

namespace Switon\Browser\Tool;

use Switon\Browser\Exception as BrowserException;
use Switon\Browser\HeadedBrowsersInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Tooling\Exception;
use Switon\Tooling\AbstractTool;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;

/** Tool `browser_snapshot` — a11y tree with eN refs for one named tab. */
class BrowserSnapshotTool extends AbstractTool
{
    #[Autowired] protected HeadedBrowsersInterface $browsers;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Snapshot the named tab: URL, title, headings, paragraphs, and lines like [e12] button "Submit". Use those refs with browser_click / browser_type on the same tab.',
            properties: [
                'tab' => [
                    'type' => 'string',
                    'description' => 'Tab id you choose. The same id keeps this page and its refs.',
                    'minLength' => 1,
                ],
            ],
            required: ['tab'],
        );
    }


    /**
     * @param array<string, mixed> $arguments
     */
    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $shouldAbort = fn (): bool => $agent->isCancelled() || $call->cancelled;
        try {
            return ['snapshot' => $this->browsers->tab($agent, (string) $arguments['tab'])->snapshot($shouldAbort)];
        } catch (BrowserException $e) {
            Exception::raise($e->getMessage());
        }
    }
    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        return true;
    }

}
