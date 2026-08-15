<?php

declare(strict_types=1);

namespace Switon\Llm;

use Switon\Ai\LlmInterface;

/**
 * In-memory catalog of {@see LlmInterface} clients.
 *
 * Road-signs:
 * - Write: {@see add()} stores {@see \Switon\Ai\Model::$id} and each alias as keys to the same client
 * - Fill: {@see LlmDiscoveryInterface} at boot
 * - Read: `get('')` is the first registered client; any other name is an exact key
 *
 * @see \Switon\Llm\LlmProviders
 */
interface LlmProvidersInterface
{
    /**
     * Register `$llm` under {@see \Switon\Ai\Model::$id}. Later `add()` of the same id replaces the client.
     * Each alias is stored as given.
     *
     * @param list<string> $alias
     */
    public function add(LlmInterface $llm, array $alias = []): void;

    public function get(string $model): LlmInterface;

    public function has(string $model): bool;

    /**
     * Registered names. An alias key is the same client as its route.
     *
     * @return array<string, LlmInterface>
     */
    public function all(): array;
}
