<?php

declare(strict_types=1);

namespace Switon\Query\Exception;

use Switon\Query\Exception as BaseException;

/**
 * Exception for invalid query filter syntax.
 *
 * Raised when filter keys, operators, or values do not match supported formats.
 *
 * @see \Switon\Query\Exception
 * @see \Switon\Query\ConditionBuilder
 * @see \Switon\Query\AbstractConditionBuilder::where()
 * @see \Switon\Orm\FilterPreprocessorInterface
 */
class InvalidFilterException extends BaseException
{
}
