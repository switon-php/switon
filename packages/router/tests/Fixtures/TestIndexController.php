<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Fixtures;

use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('')]
class TestIndexController
{
    #[GetMapping]
    public function indexAction(): void
    {
    }
}
