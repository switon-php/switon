<?php

declare(strict_types=1);

namespace Switon\Binding\Event;

use Switon\Binding\ArgumentsBinderInterface;
use ReflectionMethod;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Argument-binding prefill event.
 *
 * Log category: <code>switon.binding.action.arguments.resolving</code>
 *
 * @see \Switon\Binding\ArgumentsBinderInterface::resolve()
 * @see \Switon\Binding\Event\ActionArgumentsResolved
 */
#[EventLevel(Severity::DEBUG)]
class ActionArgumentsResolving implements SenderAwareInterface
{
    /**
     * @param ReflectionMethod $method Method being resolved
     * @param array<int|string, mixed> $arguments Pre-filled arguments by index or parameter name
     */
    public function __construct(
        public ArgumentsBinderInterface $sender,
        public ReflectionMethod $method,
        public array $arguments
    ) {
    }

    public function getSender(): ArgumentsBinderInterface
    {
        return $this->sender;
    }
}
