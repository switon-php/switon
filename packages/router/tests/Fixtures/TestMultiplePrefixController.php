<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Fixtures;

use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping(['/api/v1/user', '/api/v2/user'])]
class TestMultiplePrefixController
{
    #[GetMapping]
    public function indexAction(): void
    {
    }

    #[GetMapping('{id}')]
    public function showAction(int $id): void
    {
    }

    #[PostMapping('')]
    public function createAction(): void
    {
    }
}
