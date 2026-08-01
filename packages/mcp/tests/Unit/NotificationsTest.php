<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Mcp\Notifications;
use PHPUnit\Framework\TestCase;

#[CoversClass(Notifications::class)]
class NotificationsTest extends TestCase
{
    public function testHandleIsNoOp(): void
    {
        $notifications = new Notifications();
        $notifications->handle('notifications/initialized', []);

        $this->assertTrue(true);
    }
}
