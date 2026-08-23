<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit\Fixtures;

use Switon\Core\ContainerInterface;
use Switon\Ai\LlmInterface;
use Switon\Llm\LlmProvidersInterface;

/**
 * @internal Stub providers: fixed {@see LlmInterface}, or resolve from {@see ContainerInterface}
 * so tests that only re-bind {@see LlmInterface} still hit the stub.
 */
class StubLlmProviders implements LlmProvidersInterface
{
    public function __construct(public readonly LlmInterface|ContainerInterface $source)
    {
    }

    public function add(LlmInterface $llm, array $alias = []): void
    {
    }

    public function get(string $model): LlmInterface
    {
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
