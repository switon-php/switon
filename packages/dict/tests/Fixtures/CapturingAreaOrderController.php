<?php

declare(strict_types=1);

namespace Switon\Dict\Tests\Fixtures;

use Switon\Dict\Tests\Fixtures\App\Areas\Order\Controller\OrderController as AreaOrderController;
use Switon\Dict\Tests\Fixtures\App\Areas\Order\OrderStatus;

class CapturingAreaOrderController extends AreaOrderController
{
    public ?OrderStatus $receivedStatus = null;

    public function updateAction(OrderStatus $status): void
    {
        $this->receivedStatus = $status;
    }
}
