<?php

declare(strict_types=1);

namespace Switon\Tooling\Exception;

use Switon\Tooling\Exception as ToolingException;

/**
 * Tool argument rejected.
 *
 * Guidance: {@see \Switon\Tooling\ArgumentBinderInterface} throws {@see ArgumentBindException} before the tool runs; a handler that throws this type is already inside {@see \Switon\Tooling\ToolsInterface::call()}.
 */
abstract class ArgumentException extends ToolingException
{
}
