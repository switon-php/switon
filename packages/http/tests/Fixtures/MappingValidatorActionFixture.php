<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Fixtures;

use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;

final class MappingValidatorActionFixture
{
    #[GetMapping]
    public function getOnlyAction(): void
    {
    }

    public function noMappingAction(): void
    {
    }

    #[GetMapping]
    #[PostMapping]
    public function getOrPostAction(): void
    {
    }
}
