<?php

declare(strict_types=1);

namespace App\Areas\Org\Controller;

use App\Controller\Controller;
use App\Repository\AdminRepository;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Attribute\Fields;
use Switon\Http\Attribute\Filters;
use Switon\Http\Attribute\Orders;
use Switon\Http\Criteria;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[Authorize]
#[RequestMapping('/org/staff')]
class StaffController extends Controller
{
    #[Autowired] protected AdminRepository $adminRepository;

    #[GetMapping]
    #[Filters(['status'])]
    #[Fields(['admin_id', 'admin_name'])]
    #[Orders(['admin_name' => SORT_ASC, 'admin_id' => SORT_ASC])]
    public function listAction(Criteria $criteria): array
    {
        return $this->adminRepository->all($criteria->filters, $criteria->fields, $criteria->orders);
    }
}
