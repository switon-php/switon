<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Fixtures;

use Switon\Http\Criteria;

class CapturingCriteriaController
{
    public ?Criteria $criteria = null;

    public function capture(Criteria $criteria): void
    {
        $this->criteria = $criteria;
    }
}
