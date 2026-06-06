<?php

declare(strict_types=1);

namespace Switon\Principal;

/**
 * Minimal identity contract for normalized authentication state.
 *
 * Use when other components need current user information without depending
 * on JWT payloads, sessions, or application-specific authentication code.
 *
 * Guidance: Authentication code should normalize runtime identity here once; downstream code should read only this contract.
 *
 * Road-signs:
 * - <code>isGuest()</code> gates authenticated vs guest behavior
 * - <code>getId()</code> / <code>getName()</code> expose stable subject identifiers
 * - <code>getRoles()</code> feeds authorization checks
 * - <code>set()</code> / <code>clear()</code> update request-scoped identity state
 *
 * @see \Switon\Principal\Identity
 * @see \Switon\Authorizing\Authorization
 * @see \Switon\Http\RequestInterface
 */
interface IdentityInterface
{
    /**
     * Check whether current user is unauthenticated.
     */
    public function isGuest(): bool;

    /**
     * Get user ID for auditing and ownership checks.
     *
     * @throws \Switon\Principal\Exception\NotAuthenticatedException When the current runtime is still guest
     */
    public function getId(): int|string;

    /**
     * Get user name or principal identifier.
     *
     * @throws \Switon\Principal\Exception\NotAuthenticatedException When the current runtime is still guest
     */
    public function getName(): string;

    /**
     * Get role list for authorization checks.
     *
     * @return list<string> Empty array for guests
     */
    public function getRoles(): array;

    /**
     * Replace the current authenticated identity.
     *
     * @param int|string $id Stable subject identifier
     * @param string $name Display name or principal name
     * @param list<string> $roles Normalized role list
     *
     * Implementations trim empty role entries during set().
     */
    public function set(int|string $id, string $name, array $roles = []): void;

    /**
     * Clear the current identity and return to guest state.
     */
    public function clear(): void;
}
