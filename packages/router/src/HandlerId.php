<?php

declare(strict_types=1);

namespace Switon\Routing;

use Switon\Core\ClassName;
use Switon\Core\Naming;

use function basename;

/**
 * Identifies a handler by controller class and action method.
 *
 * Converts controller and action into a compact handler ID based on routing conventions.
 *
 * @see \Switon\Routing\HandlerIdInterface
 * @see \Switon\Core\ClassName::dotId()
 * @see \Switon\Core\Naming::kebab()
 */
class HandlerId implements HandlerIdInterface
{
    /**
     * {@inheritDoc}
     */
    public function getId(string $controller, string $action): string
    {
        return ClassName::dotId($controller, compact: true) . '::' . Naming::kebab(basename($action, 'Action'));
    }
}
