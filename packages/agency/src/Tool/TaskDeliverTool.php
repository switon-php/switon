<?php

declare(strict_types=1);

namespace Switon\Agency\Tool;

use Closure;
use Switon\Agency\DeliverAck;
use Switon\Agency\Exception;
use Switon\Agency\Exception\RunCancelledException;
use Switon\Agency\Exception\RunFailedException;
use Switon\Agency\Thread\TaskAssignMessage;
use Switon\Agency\Thread\TaskFinishSignal;
use Switon\Agency\ProfilesInterface;
use Switon\Ai\AgentInterface;
use Switon\Ai\Exception\ToolInvalidArgumentException;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Envelope;
use Switon\Ai\Letter;
use Switon\Ai\Suspension\ToolInterrupt;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolSchema;
use Switon\Ai\Turn;
use Switon\Ai\TurnStatus;
use Switon\Ai\Message\UserMessage;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClockInterface;
use Switon\Core\Strings;
use Switon\Tooling\AbstractTool;
use Throwable;

/**
 * Tool `task_deliver`: root-only async assign → {@see DeliverAck}; settle via `task_finish`.
 *
 * Guidance: Leaf id `t` + ≥5 digits; callers may continue an issued `thread_id`.
 */
class TaskDeliverTool extends AbstractTool
{
    #[Autowired] protected ProfilesInterface $profiles;
    #[Autowired] protected ClockInterface $clock;

    public function schema(string $name): ?ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            $this,
            description: 'Root only: asynchronously assign a task. Returns an ack; the result arrives later via task_finish.',
            properties: [
                'message' => [
                    'type' => 'string',
                    'description' => 'Goal, scope, and result constraints (compact|detailed, facets, format).',
                ],
                'agent' => [
                    'type' => 'string',
                    'description' => 'Worker profile id from the catalog (used when spawning or cold-starting a leaf)',
                ],
                'thread_id' => [
                    'type' => 'string',
                    'description' => 'Root only: existing task worker leaf (t + ≥5 digits, e.g. t10001) to continue.',
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'Optional catalog model (`vendor/model` or short key). Omit to use the profile default.',
                ],
            ],
            required: ['message', 'agent'],
        );
    }

    public function call(AgentInterface $agent, ToolCall $call, array $arguments): mixed
    {
        $profileId = $arguments['agent'];
        if ($profileId === '' || !$this->profiles->has($profileId)) {
            ToolInvalidArgumentException::raise('{field} value invalid', ['field' => 'agent']);
        }

        $threadId = $arguments['thread_id'] ?? null;
        if ($threadId === '') {
            $threadId = null;
        }
        if ($threadId !== null && str_contains($threadId, '.')) {
            ToolInvalidArgumentException::raise(
                'thread_id must be a leaf id like t10001 (not host.leaf).',
                ['field' => 'thread_id'],
            );
        }
        if ($threadId !== null && preg_match('/^t[1-9]\d{4,}$/', $threadId) !== 1) {
            ToolInvalidArgumentException::raise(
                'thread_id must match t + ≥5 digits (e.g. t10001).',
                ['field' => 'thread_id', 'thread_id' => $threadId],
            );
        }

        $from = $agent->thread()->lastTurn();

        if ($agent->parent() !== null) {
            Exception::raise('task_deliver is root-only; use a mounted agent_* tool for synchronous nested work.');
        }

        $pick = $arguments['model'] ?? null;
        $pick = is_string($pick) && $pick !== '' ? $pick : $this->profiles->get($profileId)->model;
        $name = $pick;

        $peer = $agent->spawn($this->profiles->get($profileId), $threadId);

        $assign = TaskAssignMessage::of($arguments['message']);
        $taskId = Strings::uuid();
        $assign->taskId = $taskId;
        $envelope = Envelope::of($assign, $name, from: $from);
        $envelope->onSettled = $this->settle($agent, $from, $assign);
        $peer->deliver($envelope);

        return DeliverAck::of([
            'task_id' => $taskId,
            'thread_id' => pathinfo($peer->threadId(), PATHINFO_EXTENSION),
            'agent' => $profileId,
            'model' => $name,
        ]);
    }

    /**
     * After a delegated drive: `task_finish` on a sealed tip, a stop, a thrown failure, or a suspend.
     * A background child cannot wait for the user, so a suspended tip fails and the worker stops.
     *
     * @return Closure(AgentInterface, Turn|Throwable): void
     */
    protected function settle(AgentInterface $agent, Turn $from, Letter $letter): Closure
    {
        $since = $this->clock->microtime();

        return function (AgentInterface $child, Turn|Throwable $result) use ($agent, $from, $letter, $since): void {
            if ($result instanceof Throwable) {
                $this->finish(
                    $child,
                    $agent,
                    $from,
                    $letter,
                    $since,
                    RunFailedException::format($result),
                    $result instanceof RunCancelledException ? 'stopped' : 'failed',
                );

                return;
            }

            if ($result->status === TurnStatus::Suspended) {
                $this->finish($child, $agent, $from, $letter, $since, $this->suspendedTaskMessage($result), 'failed');

                return;
            }

            $output = $result->lastCompletion()->output;
            $this->finish(
                $child,
                $agent,
                $from,
                $letter,
                $since,
                $output instanceof AssistantMessage ? ($output->content ?? '') : '',
                'completed',
            );
        };
    }

    /**
     * Hand the parent a `task_finish` envelope, then shut down the worker.
     *
     * Body = result; `task` attribute = last turn's first message (the assign brief).
     */
    protected function finish(
        AgentInterface $child,
        AgentInterface $agent,
        Turn           $from,
        Letter         $letter,
        float          $since,
        string         $message,
        string         $status,
    ): void {
        $fields = [
            'task_id' => $this->assignedTaskId($child, $letter),
            'thread_id' => pathinfo($child->threadId(), PATHINFO_EXTENSION),
            'status' => $status,
            'task' => $this->latestAssignedTask($child, $letter),
            'timeSpent' => $this->clock->elapsed($since),
        ];
        try {
            $agent->deliver(Envelope::of(new TaskFinishSignal($fields, $message), $from->model));
        } finally {
            $child->shutdown();
        }
    }

    protected function suspendedTaskMessage(Turn $turn): string
    {
        $interrupt = $turn->interrupt;
        $name = $interrupt instanceof ToolInterrupt ? $interrupt->name : '';
        if ($name !== '') {
            return 'Background task suspended on ' . $name . '; a child task cannot wait for the user.';
        }

        return 'Background task suspended; a child task cannot wait for the user.';
    }

    protected function assignedTaskId(AgentInterface $child, Letter $letter): string
    {
        if ($letter instanceof TaskAssignMessage && $letter->taskId !== '') {
            return $letter->taskId;
        }
        if ($child->thread()->turns === []) {
            return '';
        }
        $input = $child->thread()->lastTurn()->firstCompletion()->input;

        return $input instanceof TaskAssignMessage ? $input->taskId : '';
    }

    protected function latestAssignedTask(AgentInterface $child, Letter $letter): string
    {
        $input = $child->thread()->turns === []
            ? $letter
            : ($child->thread()->lastTurn()->firstCompletion()->input ?? $letter);

        return $input instanceof UserMessage ? ($input->text() ?? '') : '';
    }
}
