<?php

declare(strict_types=1);

namespace Switon\Rpc\Exception;

use Switon\Core\Exception\RuntimeException;

/**
 * Use when an RPC call does not receive a matching reply before timeout.
 */
class RpcTimeoutException extends RuntimeException
{
}
