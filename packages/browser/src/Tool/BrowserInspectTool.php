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

use function is_array;
use function is_string;
use function trim;

/** Tool `browser_inspect` — computed styles and box of a snapshot ref. */
class BrowserInspectTool extends AbstractTool
{
    #[Autowired] protected HeadedBrowsersInterface $browsers;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Report computed CSS and the box for a snapshot ref (e7) on the named tab: display, visibility, opacity, color, background, font-size, position, z-index, overflow. Read this to explain why an element is invisible, sized wrong, or the wrong colour.',
            properties: [
                'tab' => [
                    'type' => 'string',
                    'description' => 'Tab id you choose. The same id keeps this page and its refs.',
                    'minLength' => 1,
                ],
                'ref' => [
                    'type' => 'string',
                    'description' => 'Element ref from snapshot, e.g. e7.',
                    'minLength' => 1,
                ],
                'properties' => [
                    'type' => 'array',
                    'description' => 'CSS property names to read, e.g. ["display","width"]. Omit for a common set.',
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
        $tab = (string) $arguments['tab'];
        $ref = (string) $arguments['ref'];

        $properties = [];
        $raw = $arguments['properties'] ?? null;
        if (is_array($raw)) {
            foreach ($raw as $property) {
                if (is_string($property) && trim($property) !== '') {
                    $properties[] = trim($property);
                }
            }
        }

        try {
            return $this->browsers->tab($agent, $tab)->inspect($ref, $properties, $shouldAbort);
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
