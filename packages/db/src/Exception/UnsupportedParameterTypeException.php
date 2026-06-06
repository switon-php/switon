<?php

declare(strict_types=1);

namespace Switon\Db\Exception;

use Switon\Db\Exception;

/**
 * Exception for bind values that cannot be normalized to driver-safe SQL parameters.
 *
 * @see \Switon\Db\Exception
 * @see \Switon\Db\PreparedEmulator
 */
class UnsupportedParameterTypeException extends Exception
{
}
