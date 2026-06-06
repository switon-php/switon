<?php

declare(strict_types=1);

namespace Switon\Context;

use Switon\Core\ContextAware;

/**
 * Helper contract for resolving, creating, and resetting context instances.
 *
 * Use when you need the default <code>ContextManager</code> helper operations beyond the public core contract.
 *
 * Road-signs:
 * - open core <code>ContextManagerInterface</code> for the public <code>getContext()</code> contract
 * - <code>findContext()</code> resolves the declared <code>getContext()</code> return type
 * - <code>createContext()</code> instantiates the resolved type after validation
 * - <code>createContext()</code> validates loadable zero-argument context classes
 * - <code>resetContexts()</code> only clears the per-object non-coroutine cache
 *
 * Resolution and instantiation are split intentionally: <code>findContext()</code>
 * may return a loadable declared type, while <code>createContext()</code> performs
 * the instantiability check.
 *
 * Guidance: Use the core context contract for callers; this helper layer exposes the validation and reset hooks used by the package implementation.
 *
 * @see \Switon\Core\ContextManagerInterface
 * @see \Switon\Core\ContextAware
 * @see \Switon\Context\ContextManager
 * @see \Switon\Context\ServiceProvider
 * @see \Switon\Context\Exception\ContextException
 * @see \Switon\Testing\Container Typical consumer
 */
interface ContextManagerInterface extends \Switon\Core\ContextManagerInterface
{
    /**
     * Resolves context type from component <code>getContext()</code> return type.
     *
     * The declared return type must be one loadable named type. Concrete instantiability is validated in <code>createContext()</code>.
     *
     * @return class-string
     */
    public function findContext(ContextAware $object): string;

    /**
     * Creates a new context instance for the component.
     *
     * The resolved context must be an instantiable concrete class and must
     * not require constructor arguments.
     */
    public function createContext(ContextAware $object): object;

    /**
     * Clears the per-object cache used outside coroutine storage.
     *
     * Narrow worker/server-boundary cleanup hook; not for ordinary application services.
     * Root fallback contexts are retained.
     *
     * @internal
     */
    public function resetContexts(): void;
}
