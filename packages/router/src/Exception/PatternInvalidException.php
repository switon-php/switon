<?php

declare(strict_types=1);

namespace Switon\Routing\Exception;

use Switon\Routing\Exception;

/**
 * Exception for invalid route patterns.
 *
 * Raised when a route pattern cannot be compiled into a valid regex.
 *
 * @see \Switon\Routing\PatternCompiler
 */
class PatternInvalidException extends Exception
{
}
