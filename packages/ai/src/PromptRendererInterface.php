<?php

declare(strict_types=1);

namespace Switon\Ai;

/**
 * Interprets one prompt template with variables supplied for this call.
 *
 * Guidance: components register {@see PromptVariablesInterface} from their `boot()`; each `render()` calls `variables()`.
 *
 * Road-signs:
 * - Interpreter: {@see \Switon\Agency\PromptRenderer}
 * - Registered variables: {@see PromptVariablesInterface::variables()}
 * - Assembly: {@see \Switon\Agency\ChartersInterface::get()}
 *
 * @see \Switon\Agency\PromptRenderer
 */
interface PromptRendererInterface
{
    /**
     * `$segments` are template texts rendered before `$prompt`. `$data` wins over registered variables.
     *
     * @param list<string> $segments
     * @param array<string, mixed> $data
     *
     * @throws Exception when a block tag is malformed or unclosed
     */
    public function render(AgentInterface $agent, string $prompt = '', array $segments = [], array $data = []): string;

    /** Later registrations override the same key. Each `render()` calls {@see PromptVariablesInterface::variables()}. */
    public function registerVariables(PromptVariablesInterface $variables): void;
}
