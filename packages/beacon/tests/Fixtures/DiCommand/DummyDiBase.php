<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Fixtures\DiCommand;

use Switon\Core\Attribute\Autowired;

class DummyDiBase
{
    #[Autowired] protected string $base = 'base-value';
}
