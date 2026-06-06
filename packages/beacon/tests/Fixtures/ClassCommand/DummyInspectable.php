<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Fixtures\ClassCommand;

class DummyInspectable implements DummyContract
{
    public int $count = 0;
    protected string $name = 'demo';

    public function ping(): string
    {
        return 'pong';
    }

    /**
     * Work method doc for source extraction tests.
     */
    public function work(int $n = 1): int
    {
        return $n + 1;
    }
}
