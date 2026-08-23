<?php

declare(strict_types=1);

namespace Switon\Agency\Toolkit;

use Switon\Agency\Exception;
use Switon\Agency\ProfileDiscoveryInterface;
use Switon\Agency\ProfilesInterface;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Profile;
use Switon\Ai\AsToolCall;
use Switon\Ai\AsToolResult;
use Switon\Ai\Completion;
use Switon\Ai\Envelope;
use Switon\Ai\Signal;
use Switon\Ai\Event\AsToolCalling;
use Switon\Ai\AgentInterface;
use Switon\Ai\ToolkitInterface;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Core\Attribute\Autowired;
use Switon\Eventing\EventDispatcherInterface;

/**
 * Profile as-tool mounts: each profile's `asTool` field is already the complete wire id
 * ({@see \Switon\Ai\Profile::$asTool}). Wires resolve lazily against the catalog at
 * tool exposure — no eager index.
 *
 * @see \Switon\Ai\AsToolCall
 * @see \Switon\Ai\AsToolResult
 * @see \Switon\Ai\Event\AsToolCalling
 */
class AsToolToolkit implements ToolkitInterface
{
    /** Wire namespace convention on {@see Profile::$asTool} (`agent_file_research`). */
    protected const string WIRE_PREFIX = 'agent';

    #[Autowired] protected ProfilesInterface $profiles;
    #[Autowired] protected ProfileDiscoveryInterface $profileDiscovery;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;

    public function schema(string $name): ?ToolSchema
    {
        $profile = $this->resolve($name);
        if ($profile === null) {
            return null;
        }

        $template = self::argumentTemplate();
        $description = $template['description'];
        if ($profile->description !== '') {
            $description .= ' ' . $profile->description;
        }

        return new ToolSchema(
            $this,
            description: $description,
            properties: $template['properties'],
            required: $template['required'],
        );
    }

    /**
     * Profile mounted under `$name` (full wire `agent_file_research` or bare member
     * `file_research`), or null when none claims it. Resolved lazily against the catalog
     * rows — two rows claiming the same wire raise on resolution.
     */
    protected function resolve(string $name): ?Profile
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $hits = [];
        foreach ($this->profiles->all() as $definition) {
            if ($definition->asTool === $name || $definition->asTool === self::WIRE_PREFIX . '_' . $name) {
                $hits[] = $definition;
            }
        }

        if ($hits === []) {
            return null;
        }
        if (isset($hits[1])) {
            Exception::raise(
                'Ambiguous as-tool "{name}": profiles "{first}" and "{second}" both declare it.',
                ['name' => $name, 'first' => $hits[0]->id, 'second' => $hits[1]->id],
            );
        }

        return $hits[0];
    }

    /**
     * Shared argument shape for every as-tool (description prefix only).
     *
     * @return array{description: string, properties: array<string, array<string, mixed>>, required: list<string>}
     */
    protected static function argumentTemplate(): array
    {
        return [
            'description' => '[specialist worker] Delegate a self-contained goal; '
                . "this tool returns the specialist's final answer.",
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'description' => 'Goal, scope, and result constraints (compact|detailed, facets, format).',
                ],
                'as_reply' => [
                    'type' => 'boolean',
                    'description' => 'Final reply to the user; skip host rewrite (saves tokens). Honored only when this is the last tool call in the step.',
                    'default' => false,
                ],
            ],
            'required' => ['message'],
        ];
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $profile = $this->resolve($call->name);
        if ($profile === null) {
            Exception::raise('Unknown as-tool "{name}".', ['name' => $call->name]);
        }
        $profileId = $profile->id;

        $name = $profile->model;

        $worker = $agent->spawn($this->profiles->get($profileId));
        // Worker identity is this toolkit's own concern, so it mints the record here: the host
        // thread's real call already carries its provider fields, and the UI only needs to know
        // which child ran it.
        $record = new AsToolCall(
            id: $call->id,
            name: $call->name,
            threadId: pathinfo($worker->threadId(), PATHINFO_EXTENSION),
            agent: $profileId,
            model: $name,
        );
        $this->eventDispatcher->dispatch(new AsToolCalling(
            $agent,
            $worker,
            $call->id,
            $call->name,
            $name,
        ));

        try {
            $lastTurn = $worker->run(Envelope::of($arguments['message'], $name));
            if ($lastTurn->status === TurnStatus::Suspended) {
                Exception::raise(
                    'As-tool "{name}" suspended; sync as-tool cannot resume child suspends.',
                    ['name' => $call->name],
                );
            }
            $shell = clone $lastTurn;
            $slot = Completion::of($shell->firstCompletion()->input, $shell->lastCompletion()->output);
            $shell->completions = [$slot];

            return AsToolResult::from(
                $record,
                $shell,
                $this->replyText($lastTurn),
            );
        } finally {
            $worker->shutdown();
        }
    }

    /**
     * Assistant text, or a signal body when the child closed without one
     * ({@see \Switon\Ai\Signal\AssistantAbortSignal} has no content).
     */
    protected function replyText(Turn $turn): string
    {
        $output = $turn->lastCompletion()->output;
        if ($output instanceof AssistantMessage) {
            return (string)($output->content ?? '');
        }
        if ($output instanceof Signal) {
            return $output->body !== '' ? $output->body : $output->label();
        }

        return '';
    }

    public function reload(): void
    {
        $this->profileDiscovery->discover();
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function isReadonly(string $name, array $arguments): bool
    {
        return false;
    }

}
