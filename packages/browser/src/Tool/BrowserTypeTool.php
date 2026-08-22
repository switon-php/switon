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

/** Tool `browser_type` — type into a snapshot ref on a named tab. */
class BrowserTypeTool extends AbstractTool
{
    #[Autowired] protected HeadedBrowsersInterface $browsers;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Replace the text of a snapshot ref (e3) on the named tab and return the new snapshot.',
            properties: [
                'tab' => [
                    'type' => 'string',
                    'description' => 'Tab id you choose. The same id keeps this page and its refs.',
                    'minLength' => 1,
                ],
                'ref' => [
                    'type' => 'string',
                    'description' => 'Element ref from snapshot, e.g. e3.',
                    'minLength' => 1,
                ],
                'text' => [
                    'type' => 'string',
                    'description' => 'Text to insert.',
                ],
                'submit' => [
                    'type' => 'boolean',
                    'description' => 'Press Enter after typing.',
                    'default' => false,
                ],
            ],
            required: ['tab', 'ref', 'text'],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $shouldAbort = fn (): bool => $agent->isCancelled() || $call->cancelled;
        $submit = (bool) ($arguments['submit'] ?? false);

        try {
            $browser = $this->browsers->tab($agent, (string) $arguments['tab']);
            $browser->type((string) $arguments['ref'], (string) $arguments['text'], $submit, $shouldAbort);

            return ['snapshot' => $browser->snapshot($shouldAbort)];
        } catch (BrowserException $e) {
            Exception::raise($e->getMessage());
        }
    }
}
