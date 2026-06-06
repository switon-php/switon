<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Fixtures;

use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/api/user')]
class TestUserController
{
    #[GetMapping]
    public function indexAction(): void
    {
    }

    #[GetMapping('/{id}')]
    public function showAction(int $id): void
    {
    }

    #[PostMapping('')]
    public function createAction(): void
    {
    }

    public function helperMethod(): void
    {
    }
}
