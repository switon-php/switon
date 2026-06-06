<?php

declare(strict_types=1);

namespace Switon\Invocation\Attribute;

/**
 * Marker interface for method-level invocation guards.
 *
 * Guidance:
 * - implement a public <code>handle(...)</code> method on the attribute class
 * - guards run before interceptors and before the target method when invocation hooks are enabled
 *
 * @see \Switon\Invocation\Attribute\InterceptorInterface
 */
interface GuardInterface
{
}
