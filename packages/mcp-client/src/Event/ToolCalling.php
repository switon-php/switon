<?php

declare(strict_types=1);

namespace Switon\McpClient\Event;

use JsonSerializable;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Before `tools/call`. Listeners may replace `arguments`; that value is sent.
 *
 * Log category: `switon.mcp.client.tool.calling`
 */
#[EventLevel(Severity::DEBUG)]
class ToolCalling implements JsonSerializable
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public string $name,
        public array $arguments,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'argument_keys' => array_keys($this->arguments),
        ];
    }
}
