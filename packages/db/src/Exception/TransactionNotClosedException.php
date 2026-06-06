<?php

declare(strict_types=1);

namespace Switon\Db\Exception;

use Switon\Db\Exception;

/**
 * Exception when a transient DB client is destroyed with an open transaction.
 *
 * @see \Switon\Db\Exception
 * @see \Switon\Db\Client
 */
class TransactionNotClosedException extends Exception
{
}
