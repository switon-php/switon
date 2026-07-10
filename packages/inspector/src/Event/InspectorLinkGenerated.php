<?php

declare(strict_types=1);

namespace Switon\Inspector\Event;

use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Event emitted when an inspector link is generated.
 *
 * Log category: <code>switon.inspector.link.generated</code>
 *
 * @see \Switon\Inspector\Inspector
 */
#[EventLevel(Severity::INFO)]
class InspectorLinkGenerated implements SenderAwareInterface
{
    /**
     * @param string $url Generated inspector URL exposed to callers.
     * @param string $key Storage key used to persist the captured inspector payload.
     */
    public function __construct(
        public \Switon\Inspector\InspectorInterface $sender,
        public string $url,
        public string $key,
    ) {

    }

    public function getSender(): \Switon\Inspector\InspectorInterface
    {
        return $this->sender;
    }
}
