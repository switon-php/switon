<?php

declare(strict_types=1);

namespace Switon\Browser\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Browser\Exception as BrowserException;
use Switon\Browser\HeadedBrowsersInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Tooling\AbstractTool;
use Switon\Tooling\Exception;

use function trim;

/**
 * Tool `browser_scroll` — reach the part of the page below the fold.
 *
 * Role: a snapshot marks elements `(offscreen)` when a person cannot see them. Scrolling is how the
 * agent reaches those, and how it makes a screenshot show what it wants to inspect.
 */
class BrowserScrollTool extends AbstractTool
{
    #[Autowired] protected HeadedBrowsersInterface $browsers;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Scroll the named tab. Pass a snapshot ref to centre that element, or a pixel offset to move down. Returns the new snapshot; lines marked (offscreen) are below the fold.',
            properties: [
                'tab' => [
                    'type' => 'string',
                    'description' => 'Tab id you choose. The same id keeps this page and its refs.',
                    'minLength' => 1,
                ],
                'ref' => [
                    'type' => 'string',
                    'description' => 'Snapshot ref to bring into view, e.g. e12. Omit to scroll by pixels.',
                ],
                'y' => [
                    'type' => 'integer',
                    'description' => 'Pixels to scroll down from the top. Used when ref is omitted.',
                    'default' => 0,
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
        $tab = (string) $arguments['tab'];
        $ref = trim((string) ($arguments['ref'] ?? ''));
        $y = (int) ($arguments['y'] ?? 0);

        try {
            $browser = $this->browsers->tab($agent, $tab);
            $browser->scroll($ref, $y, $shouldAbort);

            return ['snapshot' => $browser->snapshot($shouldAbort)];
        } catch (BrowserException $e) {
            Exception::raise($e->getMessage());
        }
    }
}
