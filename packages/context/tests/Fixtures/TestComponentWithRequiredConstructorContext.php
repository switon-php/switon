<?php

declare(strict_types=1);

namespace Switon\Context\Tests\Fixtures;

use Switon\Core\ContextAware;

class TestComponentWithRequiredConstructorContext implements ContextAware
{
    public function getContext(): TestContextWithRequiredConstructor
    {
        return new TestContextWithRequiredConstructor('fixture');
    }
}
