<?php

declare(strict_types=1);

namespace Switon\Principal;

/**
 * Minimal tenant contract for normalized multi-tenant runtime state.
 *
 * Use when other components need current tenant information without depending
 * on domains, subdomains, headers, or application-specific tenant lookup code.
 *
 * Guidance: Resolve tenant at the app or HTTP boundary first; this contract only carries the resolved runtime state.
 *
 * Road-signs:
 * - <code>hasTenant()</code> gates tenant-aware vs tenantless behavior
 * - <code>getId()</code> exposes stable internal tenant identifier
 * - <code>getCode()</code> exposes stable public tenant key for routing and diagnostics
 * - <code>set()</code> / <code>clear()</code> update request-scoped tenant state
 *
 * @see \Switon\Principal\Tenant
 * @see \Switon\Http\RequestInterface
 */
interface TenantInterface
{
    /**
     * Check whether current runtime already resolved a tenant.
     */
    public function hasTenant(): bool;

    /**
     * Get internal tenant identifier for data scope and ownership checks.
     *
     * @throws \Switon\Principal\Exception\TenantNotAvailableException When the current runtime has no tenant
     */
    public function getId(): int|string;

    /**
     * Get stable public tenant key for routing, logs, and diagnostics.
     *
     * @throws \Switon\Principal\Exception\TenantNotAvailableException When the current runtime has no tenant
     */
    public function getCode(): string;

    /**
     * Replace the current resolved tenant.
     *
     * If the application uses only one tenant identifier, <code>$id</code> and <code>$code</code>
     * may carry the same value.
     *
     * @param int|string $id Stable internal tenant identifier (non-empty string when string-typed)
     * @param string $code Stable public tenant key (non-empty after trim)
     *
     * Implementations trim the tenant code during set().
     */
    public function set(int|string $id, string $code): void;

    /**
     * Clear the current tenant and return to tenantless state.
     */
    public function clear(): void;
}
