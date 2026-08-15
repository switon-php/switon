<?php

declare(strict_types=1);

namespace Switon\Llm\Tests\Unit\Fixtures;

use Switon\Ai\LlmInterface;
use Switon\Llm\LlmProvidersInterface;

/** @internal Returns one {@see LlmInterface} stub for all model names. */
class StubLlmProviders implements LlmProvidersInterface
{
    public function __construct(public readonly LlmInterface $llm)
    {
    }

    public function add(LlmInterface $llm, array $alias = []): void
    {
    }

    public function get(string $model): LlmInterface
    {
        return $this->llm;
    }

    public function has(string $model): bool
    {
        return true;
    }

    public function all(): array
    {
        return [];
    }
}
