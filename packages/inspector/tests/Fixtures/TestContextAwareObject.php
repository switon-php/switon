<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Fixtures;

use Switon\Core\ContextAware;

class TestContextAwareObject implements ContextAware
{
    public string $data = 'test';

    public function getContext(): TestContextObject
    {
        return new TestContextObject();
    }
}
