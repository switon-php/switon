<?php

declare(strict_types=1);

namespace Switon\Context\Tests\Fixtures;

use Switon\Core\ContextAware;

class TestComponentWithBuiltinReturnType implements ContextAware
{
    public function getContext(): mixed
    {
        return null;
    }
}
