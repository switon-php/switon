<?php

declare(strict_types=1);

namespace Switon\Rpc\Exception;

use Switon\Core\Exception\RuntimeException;

/**
 * Use when an RPC method target cannot be resolved from `ClassName::method`.
 */
class RpcMethodException extends RuntimeException
{
}
