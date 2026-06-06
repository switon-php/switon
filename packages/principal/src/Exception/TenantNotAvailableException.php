<?php

declare(strict_types=1);

namespace Switon\Principal\Exception;

use Switon\Principal\Exception as BaseException;

/**
 * Use when tenant-dependent code runs without a current tenant.
 *
 * @see \Switon\Principal\TenantInterface
 * @see \Switon\Principal\Tenant
 */
class TenantNotAvailableException extends BaseException
{
}
