<?php

declare(strict_types=1);

namespace Switon\Validating\Tests\Unit\Attribute;

use Switon\Core\Clock;
use Switon\Validating\Attribute\ArrayOf;
use PHPUnit\Framework\TestCase;

class ArrayOfTest extends TestCase
{
    public function testConstructorStoresTypeAndBounds(): void
    {
        $arrayOf = new ArrayOf('int', 1, 10);

        $this->assertSame('int', $arrayOf->type);
        $this->assertSame(1, $arrayOf->minItems);
        $this->assertSame(10, $arrayOf->maxItems);
    }

    public function testConstructorWithTypeOnlyLeavesBoundsNull(): void
    {
        $arrayOf = new ArrayOf(Clock::class);

        $this->assertSame(Clock::class, $arrayOf->type);
        $this->assertNull($arrayOf->minItems);
        $this->assertNull($arrayOf->maxItems);
    }
}
