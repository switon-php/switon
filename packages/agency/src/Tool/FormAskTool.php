<?php

declare(strict_types=1);

namespace Switon\Agency\Tool;

use Switon\Ai\AgentInterface;
use Switon\Ai\Suspension\ToolReplyInterrupt;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Tooling\AbstractTool;

use function is_string;

/**
 * Tool `form_ask`. Ask the user via the UI form card.
 *
 * Returns {@see ToolReplyInterrupt}; Loop suspends, emits {@see \Switon\Ai\Event\TurnSuspended},
 * and returns — app listeners own UI / continue (no {@see \Switon\Ai\ToolResult} yet).
 * Who may call is profile tool config.
 */
class FormAskTool extends AbstractTool
{
    /** @var list<string> Wire field types exposed to the LLM as schema enum. Empty = tool disabled (`schema()` null). */
    #[Autowired] protected array $types = [];

    public function schema(string $name): ?ToolSchema
    {
        if ($this->types === []) {
            return null;
        }

        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Structured form. Returns needs-reply; wait for the user\'s form answer on the same tool call.',
            properties: [
                'id' => [
                    'type' => 'string',
                    'description' => 'Unique in thread; echoed in reply.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Optional heading.',
                ],
                'fields' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'description' => 'Required: name, label, type. Optional HTML5: options (disabled, title), placeholder, required, default, disabled, readonly, minlength, maxlength, pattern, autocomplete, autofocus, title, min, max, step, rows, allow_multiple.',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'description' => 'Field key in reply values.',
                            ],
                            'label' => [
                                'type' => 'string',
                            ],
                            'type' => [
                                'type' => 'string',
                                'enum' => $this->types,
                            ],
                            'options' => [
                                'type' => 'array',
                                'description' => '[{id|value, label, disabled?, title?}] or ["a","b"].',
                            ],
                        ],
                        'required' => ['name', 'label', 'type'],
                        'additionalProperties' => true,
                    ],
                ],
            ],
            required: ['id', 'fields'],
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): ToolReplyInterrupt
    {
        $normalized = [
            'id' => $arguments['id'],
            'fields' => array_values($arguments['fields']),
        ];
        $title = $arguments['title'] ?? null;
        if (is_string($title) && $title !== '') {
            $normalized['title'] = $title;
        }

        return new ToolReplyInterrupt($call->id, $call->name, $normalized);
    }
}
