<?php

declare(strict_types=1);

namespace Switon\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Switon\Core\Suppress;

#[CoversClass(Suppress::class)]
class SuppressTest extends TestCase
{
    public function testThrowableRunsAction(): void
    {
        $ran = false;

        Suppress::throwable(static function () use (&$ran): void {
            $ran = true;
        });

        $this->assertTrue($ran);
    }

    public function testThrowableSwallowsException(): void
    {
        Suppress::throwable(static function (): void {
            throw new RuntimeException('cleanup failed');
        });

        $this->assertTrue(true);
    }
}
