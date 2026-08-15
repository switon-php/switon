<?php

declare(strict_types=1);

namespace Switon\Ai;

/**
 * One delivery's charter: `$prompt` says how to work, `$tools` and `$toolChoice` say what is allowed.
 *
 * Guidance: after prompt or tools change, `invalidate()` and mutate this instance.
 *
 * @see \Switon\Agency\ChartersInterface
 */
class Charter
{
    protected bool $invalidated = false;

    /**
     * @param array<string, ToolSchema> $tools
     * @param ?string $toolChoice `auto` | `none` | `required` | forced tool name | null
     */
    public function __construct(
        public string  $model = '',
        public ?string $effort = null,
        public string  $prompt = '',
        public array   $tools = [],
        public ?string $toolChoice = null,
    ) {
    }

    /**
     * @param array<string, ToolSchema> $tools
     */
    public static function of(
        string  $model = '',
        ?string $effort = null,
        string  $prompt = '',
        array   $tools = [],
        ?string $toolChoice = null,
    ): static {
        return new static($model, $effort, $prompt, $tools, $toolChoice);
    }

    /** Mark this charter stale; the owning agent reassembles on its next read. */
    public function invalidate(): void
    {
        $this->invalidated = true;
    }

    public function invalidated(): bool
    {
        return $this->invalidated;
    }
}
