<?php

declare(strict_types=1);

namespace Switon\Http\Filter;

use Switon\Core\Attribute\Autowired;
use Switon\Core\Attributes;
use Switon\Eventing\Attribute\EventListener;
use Switon\Http\Event\RequestValidating;
use Switon\Http\RequestInterface;
use Switon\Routing\Attribute\MappingInterface;
use Switon\Routing\Exception\MethodNotAllowedException;

/**
 * Validates controller mapping metadata before action invocation.
 *
 * Guidance: Keep HTTP verb constraints on mapping attributes; this filter only enforces what routing metadata already declares.
 *
 * Road-signs:
 * - listens on RequestValidating
 * - reads MappingInterface attributes from the action method
 * - mismatched verbs raise MethodNotAllowedException
 *
 * @see \Switon\Http\Event\RequestValidating
 * @see \Switon\Routing\Attribute\MappingInterface
 * @see \Switon\Routing\Exception\MethodNotAllowedException
 */
class MappingValidatorFilter
{
    #[Autowired] protected RequestInterface $request;

    #[EventListener] public function onValidating(RequestValidating $event): void
    {
        $mappings = Attributes::all($event->method, MappingInterface::class);
        if ($mappings === []) {
            return;
        }

        $allowed = false;

        $verb = $this->request->verb();
        foreach ($mappings as $mapping) {
            if ($mapping->getVerb() === $verb) {
                $allowed = true;
            }
        }

        if (!$allowed) {
            MethodNotAllowedException::raise('Method "{method}" not allowed.', ['method' => $verb]);
        }
    }
}
