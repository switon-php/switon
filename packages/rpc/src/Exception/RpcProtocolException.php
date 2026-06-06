<?php

declare(strict_types=1);

namespace Switon\Rpc\Exception;

use Switon\Core\Exception\RuntimeException;

/**
 * Use when an RPC message does not satisfy the expected request-reply protocol.
 */
class RpcProtocolException extends RuntimeException
{
}
