<?php

declare(strict_types=1);

namespace Switon\Tooling\Exception;

use Switon\Tooling\Exception as ToolingException;

/**
 * Schema bind rejected a tool argument.
 *
 * Guidance: thrown by {@see \Switon\Tooling\ArgumentBinderInterface} before the tool runs; a handler rejects one value with {@see \Switon\Ai\Exception\ToolInvalidArgumentException}.
 */
abstract class ArgumentException extends ToolingException
{
}
