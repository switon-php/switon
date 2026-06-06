<?php

declare(strict_types=1);

namespace Switon\Db\Exception;

use Switon\Db\Exception;

/**
 * Exception for unsupported query-condition operators.
 *
 * @see \Switon\Db\Exception
 * @see \Switon\Query\ConditionBuilder
 */
class UnknownOperatorException extends Exception
{
}
