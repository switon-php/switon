<?php

declare(strict_types=1);

namespace Switon\Mcp\Event;

use JsonSerializable;
use Switon\Core\Json;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Mcp\ToolInterface;

/**
 * Emitted after an MCP tool handler returns.
 *
 * Log category: <code>switon.mcp.tool.called</code>
 *
 * Guidance: Mutate <code>result</code> in listeners; {@see \Switon\Mcp\ProtocolHandler} returns the event value.
 * Guidance: EventLogger serialize omits arguments/result bodies (name/keys/bytes only).
 *
 * @see \Switon\Mcp\ProtocolHandler::invokeTool()
 * @see \Switon\Mcp\Event\ToolCalling
 */
#[EventLevel(Severity::DEBUG)]
class ToolCalled implements JsonSerializable
{
    /**
     * @param array<string, mixed> $arguments Arguments passed to the tool handler.
     * @param array<string, mixed> $result MCP <code>tools/call</code> result payload.
     */
    public function __construct(
        public string $name,
        public ToolInterface $tool,
        public array $arguments,
        public array $result,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'argument_keys' => array_keys($this->arguments),
            'result_bytes' => strlen(Json::stringify($this->result)),
        ];
    }
}
