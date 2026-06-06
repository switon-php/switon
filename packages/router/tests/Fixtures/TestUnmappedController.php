<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Fixtures;

use Switon\Routing\Attribute\GetMapping;

class TestUnmappedController
{
    #[GetMapping]
    public function indexAction(): void
    {
    }
}
