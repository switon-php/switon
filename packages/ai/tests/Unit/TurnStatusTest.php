<?php

declare(strict_types=1);

namespace Switon\Ai\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Ai\Tests\TestCase;
use Switon\Ai\TurnStatus;
use ValueError;

#[CoversClass(TurnStatus::class)]
class TurnStatusTest extends TestCase
{
    public function testTerminalStatusesAreSettled(): void
    {
        $this->assertTrue(TurnStatus::Completed->settled());
        $this->assertTrue(TurnStatus::Cancelled->settled());
        $this->assertTrue(TurnStatus::Aborted->settled());
    }

    public function testRunningAndSuspendedCanStillTakeCompletions(): void
    {
        $this->assertFalse(TurnStatus::Running->settled());
        $this->assertFalse(TurnStatus::Suspended->settled());
    }

    public function testValuesStayPortableAcrossStorage(): void
    {
        $this->assertSame(
            ['running', 'suspended', 'completed', 'cancelled', 'aborted'],
            array_map(static fn (TurnStatus $status): string => $status->value, TurnStatus::cases()),
        );
    }

    public function testUnknownBackingValueIsRejected(): void
    {
        $this->expectException(ValueError::class);

        TurnStatus::from('done');
    }
}
