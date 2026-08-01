<?php

declare(strict_types=1);

namespace Switon\Mcp\Event;

use JsonSerializable;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Mcp\ToolInterface;

/**
 * Emitted before an MCP tool handler runs.
 *
 * Log category: <code>switon.mcp.tool.calling</code>
 *
 * Guidance: Mutate <code>arguments</code> in listeners before the tool handler runs.
 * Guidance: EventLogger serialize omits argument bodies (name/keys only).
 *
 * @see \Switon\Mcp\ProtocolHandler::invokeTool()
 * @see \Switon\Mcp\Event\ToolCalled
 */
#[EventLevel(Severity::DEBUG)]
class ToolCalling implements JsonSerializable
{
    /**
     * @param array<string, mixed> $arguments Tool arguments from the JSON-RPC request.
     */
    public function __construct(
        public string $name,
        public ToolInterface $tool,
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
