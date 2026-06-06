<?php

declare(strict_types=1);

namespace Switon\Db\Exception;

use Switon\Db\Exception;

/**
 * Exception when commit/rollback is requested without an active transaction scope.
 *
 * @see \Switon\Db\Exception
 * @see \Switon\Db\TransactionManager
 */
class NoActiveTransactionException extends Exception
{
}
