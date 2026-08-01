<?php

declare(strict_types=1);

namespace Switon\Mcp;

/**
 * Default notification handler (no-op; replace to customize).
 *
 * @see \Switon\Mcp\NotificationsInterface
 */
class Notifications implements NotificationsInterface
{
    public function handle(string $method, array $params): void
    {
    }
}
