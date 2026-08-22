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

/** Tool `browser_click` — click a snapshot ref on a named tab. */
class BrowserClickTool extends AbstractTool
{
    #[Autowired] protected HeadedBrowsersInterface $browsers;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Click a ref from the latest snapshot (e12) of the named tab and return the new snapshot. Older refs are stale.',
            properties: [
                'tab' => [
                    'type' => 'string',
                    'description' => 'Tab id you choose. The same id keeps this page and its refs.',
                    'minLength' => 1,
                ],
                'ref' => [
                    'type' => 'string',
                    'description' => 'Element ref from snapshot, e.g. e12.',
                    'minLength' => 1,
                ],
            ],
            required: ['tab', 'ref'],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $shouldAbort = fn (): bool => $agent->isCancelled() || $call->cancelled;

        try {
            $browser = $this->browsers->tab($agent, (string) $arguments['tab']);
            $browser->click((string) $arguments['ref'], $shouldAbort);

            return ['snapshot' => $browser->snapshot($shouldAbort)];
        } catch (BrowserException $e) {
            Exception::raise($e->getMessage());
        }
    }
}
