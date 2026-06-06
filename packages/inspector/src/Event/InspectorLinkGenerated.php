<?php

declare(strict_types=1);

namespace Switon\Inspector\Event;

use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Event emitted when an inspector link is generated.
 *
 * Log category: <code>switon.debugging.inspector.link.generated</code>
 *
 * @see \Switon\Inspector\Inspector
 */
#[EventLevel(Severity::INFO)]
class InspectorLinkGenerated
{
    /**
     * @param string $url Generated inspector URL exposed to callers.
     * @param string $key Storage key used to persist the captured inspector payload.
     */
    public function __construct(
        public string $url,
        public string $key,
    ) {

    }
}
