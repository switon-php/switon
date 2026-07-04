<?php

declare(strict_types=1);

namespace Switon\Validating\Tests\Fixtures;

use Switon\Validating\AbstractConstraint;
use Switon\Validating\Validation;

class CustomErrorConstraint extends AbstractConstraint
{
    public function validate(Validation $validation): bool
    {
        return $validation->addError('Custom error for {field}');
    }
}
