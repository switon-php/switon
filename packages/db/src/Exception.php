<?php

declare(strict_types=1);

namespace Switon\Db;

/**
 * Base exception class for all database-related errors.
 *
 * @see \Switon\Db\Exception\ConnectionException
 * @see \Switon\Db\ClientInterface
 * @see \Switon\Db\TransactionManagerInterface
 */
class Exception extends \Switon\Core\Exception
{
}
