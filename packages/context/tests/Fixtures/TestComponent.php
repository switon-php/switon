<?php

declare(strict_types=1);

namespace Switon\Context\Tests\Fixtures;

use Switon\Core\ContextAware;

class TestComponent implements ContextAware
{
    public function getContext(): TestComponentContext
    {
        return new TestComponentContext();
    }
}
