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

/** Tool `browser_close` — close one tab the agent named. */
class BrowserCloseTool extends AbstractTool
{
    #[Autowired] protected HeadedBrowsersInterface $browsers;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Close the named tab. Other tabs of this agent stay open.',
            properties: [
                'tab' => [
                    'type' => 'string',
                    'description' => 'Tab id you chose when you opened it.',
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
        $tab = (string) $arguments['tab'];

        try {
            $tab = $this->browsers->close($agent, $tab);
        } catch (BrowserException $e) {
            Exception::raise($e->getMessage());
        }

        return ['closed' => $tab];
    }
}
