<?php

declare(strict_types=1);

namespace Switon\Context\Exception;

use Switon\Context\Exception;

/**
 * Exception for invalid context declarations.
 *
 * Use when <code>getContext()</code> does not declare one loadable context class that <code>ContextManager</code> can create directly.
 *
 * @see \Switon\Context\ContextManager
 * @see \Switon\Context\Exception
 */
class ContextException extends Exception
{
}
