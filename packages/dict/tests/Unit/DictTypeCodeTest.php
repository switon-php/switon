<?php

declare(strict_types=1);

namespace Switon\Dict\Tests\Unit;

use App\Dict\OrderStatus as AppDictOrderStatus;
use Switon\Dict\Tests\Fixtures\App\Areas\Order\OrderStatus as AreaOrderStatus;
use Switon\Dict\Tests\Fixtures\OrderStatus;
use Switon\Dict\Tests\TestCase;

class DictTypeCodeTest extends TestCase
{
    public function testTypeCodeReturnsExplicitValueWhenOverridden(): void
    {
        $this->assertSame('order.status', OrderStatus::typeCode());
    }

    public function testTypeCodeBuildsDotIdFromClassNameByDefault(): void
    {
        $this->assertSame('order.status', AreaOrderStatus::typeCode());
    }

    public function testTypeCodeSkipsLeadingDictNamespaceSegment(): void
    {
        $this->assertSame('order.status', AppDictOrderStatus::typeCode());
    }
}
