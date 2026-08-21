<?php

declare(strict_types=1);

namespace Switon\Ai\Exception;

use Switon\Ai\Exception as AiException;

/**
 * One bound tool argument has a value this tool does not accept.
 *
 * Guidance: schema bind stays {@see \Switon\Tooling\Exception\ArgumentBindException}; JSON decode failure is {@see InvalidToolArgumentsException}.
 */
class ToolInvalidArgumentException extends AiException
{
}
