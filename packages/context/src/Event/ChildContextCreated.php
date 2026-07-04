<?php

declare(strict_types=1);

namespace Switon\Context\Event;

use JsonSerializable;
use Switon\Context\ContextManagerInterface;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Event emitted when a child coroutine creates a new isolated context.
 *
 * Log category: <code>switon.context.child.created</code>
 * Payload: owner class, context class, child coroutine ID, and parent coroutine ID.
 *
 * @see \Switon\Context\ContextManager
 * @see \Switon\Context\Event\ChildContextInherited
 */
#[EventLevel(Severity::DEBUG)]
class ChildContextCreated implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param string $owner Context owner class
     * @param string $context Context class
     * @param int $cid Child coroutine ID
     * @param int $parentCid Parent coroutine ID
     */
    public function __construct(
        public ContextManagerInterface $sender,
        public string $owner,
        public string $context,
        public int $cid,
        public int $parentCid,
    ) {
    }

    public function getSender(): ContextManagerInterface
    {
        return $this->sender;
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
