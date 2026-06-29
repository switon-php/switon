<?php

declare(strict_types=1);

namespace Switon\Core;

/**
 * Marker contract for objects that expose their owning component instance.
 *
 * Use for pooled resources, wrappers, and nested components that need to report
 * which parent instance they belong to.
 *
 * Road-signs:
 * - implement getOwner() to return the owning instance
 * - pool seeding passes owner through maker factory parameters
 * - owner chains may span multiple levels via repeated getOwner()
 * - concrete contracts narrow `getOwner()` to the real owner type
 *
 * @template-covariant TOwner of object
 *
 * @see \Switon\Pooling\PoolManagerInterface
 */
interface OwnerAwareInterface
{
    /**
     * Return the owning component instance.
     *
     * @return TOwner
     */
    public function getOwner(): object;
}
