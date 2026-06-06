<?php

declare(strict_types=1);

namespace Switon\Context;

/**
 * Base exception for context package errors.
 *
 * Use this as the parent for invalid context declarations and context lifecycle failures.
 *
 * @see \Switon\Context\Exception\ContextException
 */
class Exception extends \Switon\Core\Exception
{
}
