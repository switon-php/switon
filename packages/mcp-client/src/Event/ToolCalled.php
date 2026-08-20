<?php

declare(strict_types=1);

namespace Switon\McpClient\Event;

use JsonSerializable;
use Switon\Core\Json;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * After `tools/call`. Listeners may replace `result`; the client returns that value.
 *
 * Log category: `switon.mcp.client.tool.called`
 */
#[EventLevel(Severity::DEBUG)]
class ToolCalled implements JsonSerializable
{
    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $result
     */
    public function __construct(
        public string $name,
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
