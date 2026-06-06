<?php

declare(strict_types=1);

namespace Switon\Db\Exception;

use Switon\Db\Exception;

/**
 * Exception thrown when a transient client is required or misused.
 *
 * @see \Switon\Db\Exception
 * @see \Switon\Db\Client
 */
class TransientClientException extends Exception
{
}
