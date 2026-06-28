<?php

declare(strict_types=1);

namespace Switon\Http\Event;

use JsonSerializable;
use ReflectionMethod;
use Switon\Core\SenderAwareInterface;
use Switon\Http\RequestHandlerInterface;

/**
 * Base payload for request dispatch lifecycle events.
 *
 * Road-signs:
 * - carries ReflectionMethod for the dispatched action
 * - exposes controller + action names for logs/observers
 * - RequestReady and similar events extend this base
 *
 * @see \Switon\Http\RequestHandler::handle()
 * @see \Switon\Http\Event\RequestReady
 */
class AbstractRequestDispatch implements JsonSerializable, SenderAwareInterface
{
    public RequestHandlerInterface $sender;
    public string $controller;
    public string $action;

    public function __construct(
        RequestHandlerInterface $sender,
        public ReflectionMethod $method,
    ) {
        $this->sender = $sender;
        $this->controller = $this->method->getDeclaringClass()->getName();
        $this->action = $this->method->name;
    }

    public function getSender(): RequestHandlerInterface
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'controller' => $this->controller,
            'action' => $this->action,
        ];
    }
}
