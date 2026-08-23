<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Fixtures;

use Switon\Agency\Tests\Fixtures\UnitTestAgent;

class StatefulRootAgent extends UnitTestAgent
{
    public function cursor(): int
    {
        return (int) ($this->thread()->state['cursor'] ?? 0);
    }

    public function setCursor(int $cursor): void
    {
        $this->thread()->state['cursor'] = $cursor;
    }
}
