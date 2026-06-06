<?php

declare(strict_types=1);

namespace Switon\Principal;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ContextAware;
use Switon\Core\ContextManagerInterface;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Principal\Exception\NotAuthenticatedException;

/**
 * Stores identity state for the current request runtime.
 *
 * Use when authentication code has already resolved a user into stable
 * <code>id</code>, <code>name</code>, and <code>roles</code> fields for the current request.
 *
 * Guidance: Keep token parsing, session lookup, and provider-specific claims mapping outside this class.
 *
 * Road-signs:
 * - auth filter resolves tokens or sessions, then calls <code>set()</code>
 * - business code reads <code>getId()</code> / <code>getName()</code> / <code>getRoles()</code>
 * - <code>clear()</code> returns the context to guest state
 *
 * @see \Switon\Principal\IdentityInterface
 * @see \Switon\Principal\IdentityContext
 * @see \Switon\Principal\Exception\NotAuthenticatedException
 */
class Identity implements IdentityInterface, ContextAware
{
    #[Autowired] protected ContextManagerInterface $contextManager;

    /**
     * Returns the request-local identity context owned by this service.
     */
    public function getContext(): IdentityContext
    {
        /** @var IdentityContext $context */
        $context = $this->contextManager->getContext($this);

        return $context;
    }

    public function isGuest(): bool
    {
        return $this->getContext()->id === null;
    }

    public function getId(): int|string
    {
        $context = $this->getContext();
        if ($context->id === null) {
            NotAuthenticatedException::raise('Cannot get user ID: user is not authenticated');
        }

        return $context->id;
    }

    public function getName(): string
    {
        $context = $this->getContext();
        if ($context->id === null) {
            NotAuthenticatedException::raise('Cannot get user name: user is not authenticated');
        }

        return $context->name ?? '';
    }

    public function getRoles(): array
    {
        return $this->isGuest() ? [] : $this->getContext()->roles;
    }

    public function set(int|string $id, string $name, array $roles = []): void
    {
        if ($id === '') {
            InvalidArgumentException::raise('Identity ID cannot be empty');
        }

        if ($name === '') {
            InvalidArgumentException::raise('Identity name cannot be empty');
        }

        $normalizedRoles = [];
        foreach ($roles as $role) {
            if (!is_scalar($role)) {
                InvalidArgumentException::raise('Identity roles must contain only scalar values');
            }

            $role = trim((string)$role);
            if ($role !== '') {
                $normalizedRoles[] = $role;
            }
        }

        $context = $this->getContext();
        $context->id = $id;
        $context->name = $name;
        $context->roles = $normalizedRoles;
    }

    public function clear(): void
    {
        $context = $this->getContext();
        $context->id = null;
        $context->name = null;
        $context->roles = [];
    }
}
