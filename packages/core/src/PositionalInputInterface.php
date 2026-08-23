<?php

declare(strict_types=1);

namespace Switon\Core;

/**
 * Input contract that exposes positional (non-named) arguments.
 *
 * Guidance: Implement on CLI and other transports that support ordered positional binding;
 * HTTP request input typically does not.
 *
 * @see \Switon\Core\InputInterface
 * @see \Switon\Cli\OptionsInterface
 * @see \Switon\Binding\ScalarResolver
 */
interface PositionalInputInterface
{
    /**
     * Positional operands before `--` (or all bare operands when `--` is absent).
     *
     * @return list<string>
     */
    public function getPositional(): array;

    /**
     * Operands after `--`, or <code>null</code> when `--` was not present.
     *
     * An empty list means `--` appeared with nothing after it.
     *
     * @return list<string>|null
     */
    public function getTrailing(): ?array;
}
