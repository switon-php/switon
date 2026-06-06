<?php

declare(strict_types=1);

namespace Switon\Rpc;

use Switon\Core\Exception as CoreException;

/**
 * Base exception for RPC request-reply failures.
 *
 * Use when RPC-specific failures need one package-level exception root.
 */
class Exception extends CoreException
{
}
