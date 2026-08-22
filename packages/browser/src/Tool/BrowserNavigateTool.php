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

use function trim;

/** Tool `browser_navigate` — open a named tab, or reuse it, and go to a URL. */
class BrowserNavigateTool extends AbstractTool
{
    #[Autowired] protected HeadedBrowsersInterface $browsers;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Open the URL in the tab you name and return a fresh snapshot (URL, title, text, and [eN] refs). The first call for that id opens the tab; later calls stay on it.',
            properties: [
                'tab' => [
                    'type' => 'string',
                    'description' => 'Tab id you choose. The same id keeps this page and its refs.',
                    'minLength' => 1,
                ],
                'url' => [
                    'type' => 'string',
                    'description' => 'Absolute URL.',
                    'minLength' => 1,
                ],
                'ready' => [
                    'type' => 'string',
                    'description' => 'Optional CSS selector to wait for before the snapshot.',
                ],
            ],
            required: ['tab', 'url'],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $shouldAbort = fn (): bool => $agent->isCancelled() || $call->cancelled;
        $tab = (string) $arguments['tab'];
        $url = (string) $arguments['url'];
        $ready = trim((string) ($arguments['ready'] ?? ''));

        try {
            $browser = $this->browsers->open($agent, $tab);
            $browser->navigate($url, $shouldAbort, $ready === '' ? null : $ready);

            return ['snapshot' => $browser->snapshot($shouldAbort)];
        } catch (BrowserException $e) {
            Exception::raise($e->getMessage());
        }
    }
}
