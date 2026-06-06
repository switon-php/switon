<?php

declare(strict_types=1);

namespace Switon\Context\Event;

use JsonSerializable;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Event emitted when a child coroutine reuses context from its parent.
 *
 * Log category: <code>switon.context.child.inherited</code>
 * Payload: owner class, context class, child coroutine ID, and parent coroutine ID.
 *
 * @see \Switon\Context\ContextManager
 * @see \Switon\Context\Event\ChildContextCreated
 * @see \Switon\Context\Event\ChildContextPropagated
 */
#[EventLevel(Severity::DEBUG)]
readonly class ChildContextInherited implements JsonSerializable
{
    /**
     * @param string $owner Context owner class
     * @param string $context Context class
     * @param int $cid Child coroutine ID
     * @param int $parentCid Parent coroutine ID
     */
    public function __construct(
        public string $owner,
        public string $context,
        public int    $cid,
        public int    $parentCid,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'owner' => $this->owner,
            'context' => $this->context,
            'cid' => $this->cid,
            'parent_cid' => $this->parentCid,
        ];
    }
}
