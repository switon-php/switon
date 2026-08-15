<?php

declare(strict_types=1);

namespace Switon\Ai;

use JsonSerializable;
use Switon\Ai\Exception\ToolAbortedException;
use Switon\Ai\Message\AssistantMessage;
use Switon\Ai\Message\ToolMessage;
use Switon\Ai\Signal\AssistantSignal;
use Switon\Ai\Suspension\Interrupt;

use function array_find;
use function count;
use function property_exists;
use function round;

/**
 * One conversation turn: ordered {@see Completion} slots plus turn-level state.
 *
 * Guidance: the hung tip is the last completion by position. The caller places rows;
 * {@see \Switon\Thread\ThreadsInterface::saveTurn()} only writes that tip to `turn.json`.
 *
 * @see \Switon\Ai\Suspension\Interrupt
 */
class Turn implements JsonSerializable
{
    /**
     * @param list<Completion> $completions Landed steps; running/suspended may end with one hung tip.
     * @param array<string, mixed> $attributes App-only metadata (not provider wire).
     */
    public function __construct(
        public string      $turnId = '',
        public TurnStatus  $status = TurnStatus::Running,
        public string      $model = '',
        public ?string     $effort = null,
        public UsageTotals $totals = new UsageTotals(),
        public array       $attributes = [],
        /** Opaque suspend reason; null when not waiting. Survives restart via turn storage. */
        public ?Interrupt  $interrupt = null,
        public float       $openedAt = 0.0,
        public float       $closedAt = 0.0,
        public array       $completions = [],
    ) {
    }

    public function __clone(): void
    {
        $cloned = [];

        $this->totals = clone $this->totals;

        if ($this->interrupt !== null) {
            $this->interrupt = clone $this->interrupt;
        }

        foreach ($this->completions as $completion) {
            $cloned[] = clone $completion;
        }
        $this->completions = $cloned;
    }

    /** @param list<Completion> $completions */
    public static function of(
        string     $turnId,
        array      $completions = [],
        TurnStatus $status = TurnStatus::Running,
        string     $model = '',
        ?string    $effort = null,
    ): static {
        return new static(
            turnId: $turnId,
            status: $status,
            model: $model,
            effort: $effort,
            completions: $completions,
        );
    }

    public function firstCompletion(): Completion
    {
        return array_first($this->completions);
    }

    public function lastCompletion(): Completion
    {
        return array_last($this->completions);
    }

    /**
     * Tool call with `$callId` requested anywhere on this turn — a missing call is corrupt
     * state, so this throws rather than returning null.
     *
     * @throws \Switon\Ai\Exception when no completion on this turn requested `$callId`
     */
    public function toolCall(string $callId): ToolCall
    {
        for ($ci = count($this->completions) - 1; $ci >= 0; --$ci) {
            $call = array_find(
                $this->completions[$ci]->toolCalls(),
                static fn (ToolCall $call): bool => $call->id === $callId,
            );
            if ($call !== null) {
                return $call;
            }
        }

        Exception::raise('Tool call {callId} not found on turn {turnId}.', [
            'callId' => $callId,
            'turnId' => $this->turnId,
        ]);
    }

    /**
     * End this turn with `$signal`.
     *
     * Unfinished tool calls are aborted first. Calls still on the tip append a tool row; a tool row
     * that already holds part of the batch gains the rest. The signal lands on that row, or on the
     * hung tip when there is nothing to abort.
     */
    public function close(AssistantSignal $signal, float $landedAt): void
    {
        $tip = $this->lastCompletion();
        $output = $tip->output;
        if ($output instanceof AssistantMessage && $output->toolCalls !== []) {
            $results = [];
            foreach ($output->toolCalls as $call) {
                $results[] = ToolResult::of($call, ToolAbortedException::of('This call was not completed.'));
            }
            $row = Completion::of(ToolMessage::of($results));
            $row->step = $tip->step + 1;
            $this->completions[] = $row;
            $tip = $row;
        } elseif ($output === null && $tip->input instanceof ToolMessage) {
            $input = $tip->input;
            $index = count($this->completions) - 2;
            $requested = $index >= 0 ? $this->completions[$index]->toolCalls() : [];
            $landed = [];
            foreach ($input->results as $result) {
                $landed[$result->id] = true;
            }
            foreach ($requested as $call) {
                if (!isset($landed[$call->id])) {
                    $input->results[] = ToolResult::of($call, ToolAbortedException::of('This call was not completed.'));
                }
            }
        }

        $tip->output = $signal;
        $tip->landedAt = $landedAt;
        $this->status = $signal->status();
    }

    /** Committed or aborted — no further completions land on this turn. */
    public function isSettled(): bool
    {
        return $this->status->settled();
    }

    /**
     * Roll up completion usage, call counts, and LLM wall. An LLM step is any completion with an
     * {@see AssistantMessage} output.
     *
     * @param bool $includeNested Fold {@see AsToolResult} child turns in. Off for this datadir's
     *                            own `totals.json` — the child writes its own.
     */
    public function usageTotals(bool $includeNested = true): UsageTotals
    {
        $totals = new UsageTotals();
        $wall = 0.0;

        foreach ($this->completions as $completion) {
            if ($completion->output instanceof AssistantMessage) {
                ++$totals->llmCalls;
                if ($completion->usage !== null) {
                    $totals->add(new UsageTotals(usage: $completion->usage));
                }
                if ($completion->timing !== null) {
                    $wall += $completion->timing->wall;
                }
            }

            if (!$completion->input instanceof ToolMessage) {
                continue;
            }

            foreach ($completion->input->results as $result) {
                if ($result instanceof AsToolResult) {
                    if ($includeNested) {
                        $totals->add($result->turn->totals);
                    }

                    continue;
                }

                ++$totals->toolCalls;
            }
        }

        $totals->llmWall = round($totals->llmWall + $wall, 3);

        return $totals;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }

    /**
     * Guidance: Decodes trusted internal storage only; do not pass external input.
     *
     * @param array<string, mixed> $data
     */
    public static function decode(array $data): static
    {
        $completions = [];
        foreach ($data['completions'] as $row) {
            $completions[] = Completion::decode($row);
        }
        $data['completions'] = $completions;

        $data['totals'] = UsageTotals::decode($data['totals']);

        if ($data['interrupt'] !== null) {
            /** @var class-string<Interrupt> $class */
            $class = $data['interrupt']['class'];
            $data['interrupt'] = $class::decode($data['interrupt']);
        }

        /** @var string $status */
        $status = $data['status'];
        $data['status'] = TurnStatus::from($status);

        $turn = new static();
        foreach ($data as $key => $value) {
            if (!property_exists($turn, $key)) {
                continue;
            }
            $turn->$key = $value;
        }

        return $turn;
    }
}
