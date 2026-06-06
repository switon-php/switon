<?php

declare(strict_types=1);

namespace Switon\Db\Exception;

use Switon\Db\Exception;

/**
 * Exception thrown when an ORM-level transaction scope violates cross-connection constraints.
 *
 * @see \Switon\Db\Exception
 * @see \Switon\Db\TransactionManager
 */
class CrossDatabaseTransactionException extends Exception
{
}
