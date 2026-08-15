<?php

declare(strict_types=1);

namespace Switon\Ai;

/**
 * Runtime thread aggregate: ordered {@see Turn} history bound to a storage leaf.
 *
 * Guidance: `lastTurn()` / `lastCompletion()` require a turn. `datadir` null is memory only —
 * no persistence; store calls are no-ops.
 *
 * Road-signs:
 * - Storage: `datadir` is opaque here — a leaf the caller binds at construction; layout,
 *   lifecycle, and crash semantics are owned by {@see \Switon\Thread\ThreadsInterface}.
 *   Null skips persistence entirely.
 * - Working context: {@see \Switon\Thread\ExcerptExtension} folds older rungs
 * - Provider encode: adapters walk `$turns` in order and skip the hung tip ({@see LlmInterface})
 * - Inbox control: {@see Suspension\Continuation} mail never lands here (not provider content)
 *
 * @see \Switon\Thread\Threads
 */
class Thread
{
    /**
     * @param list<Turn> $turns
     * @param array<string, mixed> $state
     */
    public function __construct(
        public string $id,
        public array  $turns = [],
        public array  $state = [],
        public ?string $datadir = null,
    ) {
    }

    /**
     * @param string $id Thread id. Sub-agents pass `host.leaf`.
     * @param ?string $datadir Null keeps the thread in memory. Any other string is the storage leaf.
     */
    public static function of(string $id, ?string $datadir = null): static
    {
        return new static($id, datadir: $datadir);
    }

    public function firstTurn(): Turn
    {
        return array_first($this->turns);
    }

    public function lastTurn(): Turn
    {
        return array_last($this->turns);
    }

    public function firstCompletion(): Completion
    {
        return $this->firstTurn()->firstCompletion();
    }

    public function lastCompletion(): Completion
    {
        return $this->lastTurn()->lastCompletion();
    }

    public function __clone(): void
    {
        $cloned = [];
        foreach ($this->turns as $turn) {
            $cloned[] = clone $turn;
        }
        $this->turns = $cloned;
    }

    /** @return list<Completion> */
    public function completions(): array
    {
        $completions = [];
        foreach ($this->turns as $turn) {
            foreach ($turn->completions as $completion) {
                $completions[] = $completion;
            }
        }

        return $completions;
    }
}
