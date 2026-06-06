<?php

declare(strict_types=1);

namespace Switon\Context\Tests\Fixtures;

use Switon\Core\ContextAware;

class TestComponentWithIsolatedContext implements ContextAware
{
    public function getContext(): TestIsolatedContext
    {
        return new TestIsolatedContext();
    }
}
