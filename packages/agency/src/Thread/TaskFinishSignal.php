<?php

declare(strict_types=1);

namespace Switon\Agency\Thread;

use Switon\Ai\Signal\UserSignal;

/**
 * Worker reports back to its delegating parent. Label `task_finish.{status}`.
 *
 * Guidance: written only by {@see \Switon\Agency\Tool\TaskDeliverTool::finish()}; arrives on the
 * user side so the parent model reads it as new input.
 */
class TaskFinishSignal extends UserSignal
{
    public const string NAME = 'task_finish';

    /**
     * Written only by {@see \Switon\Agency\Tool\TaskDeliverTool::finish()}.
     *
     * @var array{task_id: string, thread_id: string, status: string, task?: string, timeSpent: float}
     */
    public array $fields = [];

    /** @param array<string, scalar|null> $fields */
    public function __construct(array $fields = [], string $body = '')
    {
        parent::__construct(self::NAME, $fields, $body);
    }

    /** Reader line: completed/failed prefer the task brief; stopped prefers the result body. */
    public function text(): ?string
    {
        $task = $this->fields['task'] ?? '';
        $detail = $this->fields['status'] === 'stopped'
            ? ($this->body !== '' ? $this->body : $task)
            : ($task !== '' ? $task : $this->body);

        $label = $this->label();

        return $detail === '' ? "[$label]" : "[$label] $detail";
    }

    /** `fields.status` (completed | stopped | failed). */
    public function kind(): string
    {
        return $this->fields['status'];
    }

    public function label(): string
    {
        return self::NAME . '.' . $this->kind();
    }

    public function taskId(): string
    {
        return $this->fields['task_id'];
    }

    /** Latest assign brief echoed from the worker; empty when unknown. */
    public function task(): string
    {
        return $this->fields['task'] ?? '';
    }
}
