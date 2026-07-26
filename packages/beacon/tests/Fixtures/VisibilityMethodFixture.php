<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Fixtures;

final class VisibilityMethodFixture
{
    public function publicMethod(): void
    {
    }

    protected function protectedMethod(): void
    {
    }

    private function privateMethod(): void
    {
    }
}
