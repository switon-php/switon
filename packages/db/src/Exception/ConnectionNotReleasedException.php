<?php

declare(strict_types=1);

namespace Switon\Db\Exception;

use Switon\Db\Exception;

/**
 * Exception when a transient DB client exits without releasing its pooled connection.
 *
 * @see \Switon\Db\Exception
 * @see \Switon\Db\Client
 */
class ConnectionNotReleasedException extends Exception
{
}
