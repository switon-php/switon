<?php

declare(strict_types=1);

namespace Switon\Context\Tests\Fixtures;

class TestContextWithRequiredConstructor
{
    public function __construct(public string $name)
    {
    }
}
