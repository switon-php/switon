<?php

declare(strict_types=1);

namespace Switon\Db\Exception;

use Switon\Db\Exception;

/**
 * Exception for connection-establishment failures in DB adapters.
 *
 * @see \Switon\Db\AbstractConnection
 * @see \Switon\Db\Exception
 */
class ConnectionException extends Exception
{
}
