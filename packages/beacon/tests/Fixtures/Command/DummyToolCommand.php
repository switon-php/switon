<?php

declare(strict_types=1);

namespace Switon\Beacon\Tests\Fixtures\Command;

class DummyToolCommand
{
    public function defaultAction(string $query = ''): void
    {
    }

    public function runTaskAction(int $limit = 10, bool $dryRun = false): void
    {
    }

    public function helper(): void
    {
    }
}
