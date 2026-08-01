<?php

declare(strict_types=1);

namespace Switon\Mcp;

/**
 * Handles inbound MCP client notifications (`notifications/*` methods).
 *
 * @see \Switon\Mcp\Notifications
 * @see \Switon\Mcp\ProtocolHandler
 */
interface NotificationsInterface
{
    /**
     * @param string $method Full method name (e.g. `notifications/initialized`).
     * @param array<string, mixed> $params
     */
    public function handle(string $method, array $params): void;
}
