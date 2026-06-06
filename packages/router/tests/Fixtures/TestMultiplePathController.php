<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Fixtures;

use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/api/product')]
class TestMultiplePathController
{
    #[GetMapping(['list', 'all'])]
    public function listAction(): void
    {
    }
}
