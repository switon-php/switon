<?php

declare(strict_types=1);

namespace Switon\Schedule\Tests\Unit;

use Switon\Schedule\Expression;
use PHPUnit\Framework\TestCase;

class ExpressionTest extends TestCase
{
    public function testEveryMinuteAtSecondZero(): void
    {
        $expr = new Expression();
        $ts = strtotime('2024-06-15 10:20:00 UTC');
        $this->assertTrue($expr->matches('0 * * *', $ts));

        $ts2 = strtotime('2024-06-15 10:20:03 UTC');
        $this->assertFalse($expr->matches('0 * * *', $ts2));
    }

    public function testRejectsNonFourFields(): void
    {
        $expr = new Expression();
        $ts = strtotime('2024-06-15 10:20:00 UTC');
        $this->assertFalse($expr->matches('* * * * *', $ts));
        $this->assertFalse($expr->matches('0 * * * * *', $ts));
    }

    public function testSecondFieldLiteral(): void
    {
        $expr = new Expression();
        $ts = strtotime('2024-06-15 10:20:30 UTC');

        $this->assertTrue($expr->matches('30 * * *', $ts));
        $this->assertFalse($expr->matches('31 * * *', $ts));
    }

    public function testStepFieldOnMinute(): void
    {
        $expr = new Expression();
        $ts = strtotime('2024-06-15 10:20:00 UTC');

        $this->assertTrue($expr->matches('0 */2 * *', $ts));

        $tsOdd = strtotime('2024-06-15 10:21:00 UTC');
        $this->assertFalse($expr->matches('0 */2 * *', $tsOdd));
    }

    public function testHourFieldLiteral(): void
    {
        $expr = new Expression();
        $ts = strtotime('2024-06-15 14:30:00 UTC');

        $this->assertTrue($expr->matches('0 30 14 *', $ts));

        $tsOtherHour = strtotime('2024-06-15 13:30:00 UTC');
        $this->assertFalse($expr->matches('0 30 14 *', $tsOtherHour));
    }

    public function testDayOfMonthFieldLiteral(): void
    {
        $expr = new Expression();
        $ts = strtotime('2024-06-15 12:00:00 UTC');

        $this->assertTrue($expr->matches('0 0 12 15', $ts));

        $tsOtherDom = strtotime('2024-06-16 12:00:00 UTC');
        $this->assertFalse($expr->matches('0 0 12 15', $tsOtherDom));
    }

    public function testStepZeroIsNotValid(): void
    {
        $expr = new Expression();
        $ts = strtotime('2024-06-15 10:00:00 UTC');

        $this->assertFalse($expr->matches('*/0 * * *', $ts));
    }

    public function testMatchesUsesTimezoneWallClock(): void
    {
        $expr = new Expression();
        // 2024-06-15 16:00:00 UTC → 2024-06-16 00:00:00 in Asia/Shanghai
        $ts = strtotime('2024-06-15 16:00:00 UTC');

        $this->assertTrue($expr->matches('0 0 0 *', $ts, 'Asia/Shanghai'));
        $this->assertFalse($expr->matches('0 0 0 *', $ts));
        $this->assertFalse($expr->matches('0 0 0 *', $ts, null));
    }

    public function testWildcardExpressionMatchesArbitraryTimestamp(): void
    {
        $expr = new Expression();
        $ts = strtotime('2024-12-01 23:59:58 UTC');

        $this->assertTrue($expr->matches('* * * *', $ts));
    }
}
