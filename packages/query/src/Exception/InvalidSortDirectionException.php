<?php

declare(strict_types=1);

namespace Switon\Query\Exception;

use Switon\Query\Exception as BaseException;

/**
 * Exception for unsupported sort direction in <code>orderBy()</code>.
 *
 * Raised when direction is not one of the accepted ascending or descending values.
 *
 * @see \Switon\Query\Exception
 * @see \Switon\Query\AbstractQuery
 */
class InvalidSortDirectionException extends BaseException
{
}
