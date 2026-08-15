<?php

declare(strict_types=1);

namespace Switon\Llm;

/**
 * Check whether a provider error is a context-window overflow.
 *
 * Bind another implementation to replace the phrases in {@see ContextOverflowChecker}.
 */
interface ContextOverflowCheckerInterface
{
    public function matches(?string $code, string $message): bool;
}
