<?php

declare(strict_types=1);

namespace Switon\Query\Exception;

use Switon\Query\Exception as BaseException;

/**
 * Exception for invalid pagination arguments.
 *
 * Raised when page or size values are outside supported bounds.
 *
 * @see \Switon\Query\Exception
 * @see \Switon\Query\Paginator
 * @see \Switon\Query\AbstractQuery
 */
class PaginationException extends BaseException
{
}
