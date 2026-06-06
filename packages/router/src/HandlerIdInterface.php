<?php

declare(strict_types=1);

namespace Switon\Routing;

/**
 * Controller-action to handler-id mapper.
 *
 * Guidance: Use this for stable authorization/RBAC keys; it assumes controller naming conventions.
 *
 * Road-signs:
 * - input: controller FQCN + action method
 * - output: compact handler identifier
 * - Authorization and RBAC are typical consumers
 *
 * @see \Switon\Routing\HandlerId
 * @see \Switon\Authorizing\Authorization
 */
interface HandlerIdInterface
{
    /**
     * Convert one controller action into a handler ID.
     */
    public function getId(string $controller, string $action): string;
}
