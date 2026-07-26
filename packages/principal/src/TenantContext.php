<?php

declare(strict_types=1);

namespace Switon\Principal;

use Switon\Core\ContextConnScoped;

/**
 * Stores request-scoped tenant state.
 *
 * Use as the mutable context behind <code>Tenant</code> for the current request runtime.
 * Fields stay normalized to stable tenant <code>id</code> and <code>code</code> only.
 * Implements {@see ContextConnScoped} so WebSocket open tenant selection survives later messages.
 *
 * @see \Switon\Principal\Tenant
 */
class TenantContext implements ContextConnScoped
{
    public int|string|null $id = null;

    public ?string $code = null;
}
