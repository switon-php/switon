<?php

declare(strict_types=1);

namespace Switon\Validating\Tests\Unit\Constraint\Attribute;

use Switon\Validating\Attribute\EnumValue;
use Switon\Validating\Tests\TestCase;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class EnumValueTest extends TestCase
{
    public function testEnumValuePassesForMatchingBackedEnumValue(): void
    {
        $constraint = new EnumValue(EnumValueFixtureStatus::class);
        $validation = $this->validator->beginValidate([]);
        $validation->field = 'status';
        $validation->value = 'active';

        $this->assertTrue($constraint->validate($validation));
    }

    public function testEnumValueReturnsFalseForInvalidEnumClass(): void
    {
        $constraint = new EnumValue('App\\Missing\\StatusEnum');
        $validation = $this->validator->beginValidate([]);
        $validation->field = 'status';
        $validation->value = 'active';

        $this->assertFalse($constraint->validate($validation));
    }
}

enum EnumValueFixtureStatus: string
{
    case Active = 'active';
}
