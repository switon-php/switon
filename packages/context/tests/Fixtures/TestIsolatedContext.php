<?php

declare(strict_types=1);

namespace Switon\Context\Tests\Fixtures;

use Switon\Core\ContextIsolated;

class TestIsolatedContext implements ContextIsolated
{
    public array $data = [];
    public int $counter = 0;
}
