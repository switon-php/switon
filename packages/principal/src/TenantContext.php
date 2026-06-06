<?php

declare(strict_types=1);

namespace Switon\Principal;

/**
 * Stores request-scoped tenant state.
 *
 * Use as the mutable context behind <code>Tenant</code> for the current request runtime.
 * Fields stay normalized to stable tenant <code>id</code> and <code>code</code> only.
 *
 * @see \Switon\Principal\Tenant
 */
class TenantContext
{
    public int|string|null $id = null;

    public ?string $code = null;
}
