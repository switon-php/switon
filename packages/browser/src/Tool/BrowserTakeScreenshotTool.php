<?php

declare(strict_types=1);

namespace Switon\Browser\Tool;

use Switon\Browser\Exception as BrowserException;
use Switon\Browser\HeadedBrowsersInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Tooling\Exception;
use Switon\Tooling\AbstractTool;
use Switon\Ai\AgentInterface;
use Switon\Ai\Part\MediaPart;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolResult;
use Switon\Ai\ToolSchema;

/**
 * Tool `browser_take_screenshot` — PNG of one named tab.
 *
 * Aligns with Playwright MCP naming. Prefer {@see BrowserSnapshotTool} for element refs.
 */
class BrowserTakeScreenshotTool extends AbstractTool
{
    #[Autowired] protected HeadedBrowsersInterface $browsers;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Screenshot the named tab. The image is attached. Clicking uses browser_snapshot refs, not pixels.',
            properties: [
                'tab' => [
                    'type' => 'string',
                    'description' => 'Tab id you choose. The same id keeps this page and its refs.',
                    'minLength' => 1,
                ],
                'fullPage' => [
                    'type' => 'boolean',
                    'description' => 'When true, capture the full scrollable page instead of the viewport.',
                    'default' => false,
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
        $fullPage = (bool) ($arguments['fullPage'] ?? $arguments['full_page'] ?? false);

        try {
            $shot = $this->browsers->tab($agent, $tab)->takeScreenshot($fullPage, $shouldAbort);

            return new ToolResult(
                ['url' => $shot['url'], 'path' => $shot['path']],
                parts: [MediaPart::of($shot['path'], 'image/png')],
            );
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
