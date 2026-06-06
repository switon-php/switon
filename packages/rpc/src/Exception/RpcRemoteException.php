<?php

declare(strict_types=1);

namespace Switon\Rpc\Exception;

use Switon\Core\Exception\RuntimeException;

/**
 * Use when the remote RPC method returns an error reply.
 */
class RpcRemoteException extends RuntimeException
{
}
