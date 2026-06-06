<?php

declare(strict_types=1);

namespace App\Areas\Org\Controller;

use App\Areas\Org\Entity\StaffGroup;
use App\Areas\Org\Repository\StaffGroupRepository;
use App\Controller\Controller;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Attribute\Filters;
use Switon\Http\Attribute\Orders;
use Switon\Http\Criteria;
use Switon\Orm\Page;
use Switon\Query\Paginator;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewGetMapping;

#[Authorize]
#[RequestMapping('/org/staff-group')]
class StaffGroupController extends Controller
{
    #[Autowired] protected StaffGroupRepository $staffGroupRepository;

    #[ViewGetMapping]
    #[Filters(['id', 'staff_id', 'group_id'])]
    #[Orders(['id' => SORT_DESC])]
    public function indexAction(Page $page, Criteria $criteria): Paginator
    {
        return $this->staffGroupRepository->paginate($page, $criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[PostMapping]
    public function createAction(int $staff_id, int $group_id): StaffGroup
    {
        $existing = $this->staffGroupRepository->first(
            ['staff_id' => $staff_id, 'group_id' => $group_id],
            ['id', 'staff_id', 'group_id']
        );
        if ($existing) {
            return $existing;
        }

        return $this->staffGroupRepository->create([
            'staff_id' => $staff_id,
            'group_id' => $group_id,
        ]);
    }

    #[PostMapping]
    public function editAction(StaffGroup $staffGroup): StaffGroup
    {
        return $this->staffGroupRepository->update($staffGroup);
    }

    #[PostMapping]
    public function deleteAction(StaffGroup $staffGroup): StaffGroup
    {
        return $this->staffGroupRepository->delete($staffGroup);
    }
}
