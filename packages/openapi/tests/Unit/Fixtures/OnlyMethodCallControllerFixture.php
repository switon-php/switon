<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Unit\Fixtures;

class OnlyMethodCallControllerFixture
{
    public function saveAction(): void
    {
        $this->persist();
    }

    protected function persist(): void
    {
    }
}
