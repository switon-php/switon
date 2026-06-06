<?php

declare(strict_types=1);

namespace Switon\Principal;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ContextAware;
use Switon\Core\ContextManagerInterface;
use Switon\Core\Exception\InvalidArgumentException;
use Switon\Principal\Exception\TenantNotAvailableException;

/**
 * Stores normalized tenant state for the current request runtime.
 *
 * Use when tenant resolution code has already resolved a tenant into stable
 * <code>id</code> and <code>code</code> fields for the current request.
 *
 * Guidance: Keep domain/host/header parsing outside this class; set tenant here only after another boundary resolved it.
 *
 * Road-signs:
 * - tenant finder or filter calls <code>set()</code>
 * - business code reads <code>getId()</code> / <code>getCode()</code>
 * - <code>clear()</code> returns the context to tenantless state
 *
 * @see \Switon\Principal\TenantInterface
 * @see \Switon\Principal\TenantContext
 * @see \Switon\Principal\Exception\TenantNotAvailableException
 */
class Tenant implements TenantInterface, ContextAware
{
    #[Autowired] protected ContextManagerInterface $contextManager;

    /**
     * Returns the request-local tenant context owned by this service.
     */
    public function getContext(): TenantContext
    {
        /** @var TenantContext $context */
        $context = $this->contextManager->getContext($this);

        return $context;
    }

    public function hasTenant(): bool
    {
        return $this->getContext()->id !== null;
    }

    public function getId(): int|string
    {
        $context = $this->getContext();
        if ($context->id === null) {
            TenantNotAvailableException::raise('Cannot get tenant ID: no current tenant is available');
        }

        return $context->id;
    }

    public function getCode(): string
    {
        $context = $this->getContext();
        if ($context->id === null) {
            TenantNotAvailableException::raise('Cannot get tenant code: no current tenant is available');
        }

        return $context->code ?? '';
    }

    public function set(int|string $id, string $code): void
    {
        if (is_string($id) && trim($id) === '') {
            InvalidArgumentException::raise('Tenant ID cannot be empty');
        }

        $normalizedCode = trim($code);
        if ($normalizedCode === '') {
            InvalidArgumentException::raise('Tenant code cannot be empty');
        }

        $context = $this->getContext();
        $context->id = $id;
        $context->code = $normalizedCode;
    }

    public function clear(): void
    {
        $context = $this->getContext();
        $context->id = null;
        $context->code = null;
    }
}
