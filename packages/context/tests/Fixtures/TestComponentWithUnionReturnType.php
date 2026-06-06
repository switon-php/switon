<?php

declare(strict_types=1);

namespace Switon\Context\Tests\Fixtures;

use Switon\Core\ContextAware;

class TestComponentWithUnionReturnType implements ContextAware
{
    public function getContext(): TestComponentContext|TestIsolatedContext
    {
        return new TestComponentContext();
    }
}
