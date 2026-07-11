<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Fixtures;

use Switon\Http\Attribute\IdentityFilter;
use Switon\Http\Criteria;

class IdentityFilterCriteriaController extends CapturingCriteriaController
{
    #[IdentityFilter('owner_id')]
    public function indexAction(Criteria $criteria): void
    {
        $this->capture($criteria);
    }
}
