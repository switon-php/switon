<?php

declare(strict_types=1);

namespace Switon\Db\Exception;

use Switon\Db\Exception;

/**
 * Exception when a write/transaction call targets a readonly connection.
 *
 * @see \Switon\Db\Exception
 * @see \Switon\Db\Client
 */
class ReadonlyException extends Exception
{
}
