<?php

declare(strict_types=1);

namespace Switon\Tooling\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Tooling\ToolsInterface;

/**
 * Emitted by {@see ToolsInterface::pick()} when a requested id is unusable.
 *
 * Log category: `switon.tooling.tool.missing`
 */
#[EventLevel(Severity::INFO)]
class ToolMissing implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public ToolsInterface $sender,
        public string $name,
    ) {
    }

    public function getSender(): ToolsInterface
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name];
    }
}
