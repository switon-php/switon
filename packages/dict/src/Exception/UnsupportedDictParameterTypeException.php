<?php

declare(strict_types=1);

namespace Switon\Dict\Exception;

use Switon\Dict\Exception;

/**
 * Use when DictResolver receives a parameter type that is not a Dict subclass.
 */
class UnsupportedDictParameterTypeException extends Exception
{
}
