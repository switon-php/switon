<?php

declare(strict_types=1);

namespace Switon\Db\Exception;

use Switon\Db\Exception;

/**
 * Exception for rejected UPDATE/DELETE operations with missing or unsafe conditions.
 *
 * @see \Switon\Db\Exception
 * @see \Switon\Db\Client
 */
class UnsafeOperationException extends Exception
{
}
