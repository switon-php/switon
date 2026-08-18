<?php

declare(strict_types=1);

namespace Switon\Thread\Tests\Unit\Fixtures;

use Switon\Ai\LlmInterface;
use Switon\Core\ContainerInterface;
use Switon\Llm\LlmProvidersInterface;

/**
 * @internal Stub providers: fixed {@see LlmInterface}, or resolve from {@see ContainerInterface}.
 */
class StubLlmProviders implements LlmProvidersInterface
{
    public ?string $requested = null;

    public function __construct(public readonly LlmInterface|ContainerInterface $source)
    {
    }

    public function add(LlmInterface $llm, array $alias = []): void
    {
    }

    public function get(string $model): LlmInterface
    {
        $this->requested = $model;

        return $this->source instanceof LlmInterface
            ? $this->source
            : $this->source->get(LlmInterface::class);
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
