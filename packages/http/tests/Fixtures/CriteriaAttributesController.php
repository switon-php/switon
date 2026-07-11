<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Fixtures;

use Switon\Http\Attribute\Fields;
use Switon\Http\Attribute\Orders;
use Switon\Http\Attribute\SoftDeleteFilter;
use Switon\Http\Criteria;
use Switon\Routing\Attribute\GetMapping;

class CriteriaAttributesController extends CapturingCriteriaController
{
    #[Fields(['id', 'email'])]
    #[Orders(['created_at' => -1])]
    #[SoftDeleteFilter('deleted_at')]
    #[GetMapping('/items')]
    public function indexAction(Criteria $criteria): void
    {
        $this->capture($criteria);
    }
}
