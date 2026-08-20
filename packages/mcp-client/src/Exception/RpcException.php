<?php

declare(strict_types=1);

namespace Switon\McpClient\Exception;

use Switon\McpClient\Exception as McpClientException;

/**
 * Use when a decoded JSON-RPC response contains an `error` object.
 */
class RpcException extends McpClientException
{
}
