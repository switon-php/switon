<?php

declare(strict_types=1);

namespace App\Areas\Org\Controller;

use App\Areas\Org\Entity\StaffPosition;
use App\Areas\Org\Repository\StaffPositionRepository;
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
use Switon\Validating\Attribute\Type;

#[Authorize]
#[RequestMapping('/org/staff-position')]
class StaffPositionController extends Controller
{
    #[Autowired] protected StaffPositionRepository $staffPositionRepository;

    #[ViewGetMapping]
    #[Filters(['id', 'staff_id', 'position_id'])]
    #[Orders(['id' => SORT_DESC])]
    public function indexAction(Page $page, Criteria $criteria): Paginator
    {
        return $this->staffPositionRepository->paginate($page, $criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[PostMapping]
    public function editAction(StaffPosition $staffPosition): StaffPosition
    {
        if ($staffPosition->is_primary === 1) {
            $this->staffPositionRepository->updateAll(['staff_id' => $staffPosition->staff_id], ['is_primary' => 0]);
        }

        return $this->staffPositionRepository->update($staffPosition);
    }

    #[PostMapping]
    public function createAction(int $staff_id, int $position_id, #[Type('bit')] int $is_primary = 0): string|StaffPosition
    {
        $staffPosition = $this->staffPositionRepository->first(
            ['staff_id' => $staff_id, 'position_id' => $position_id],
            ['id', 'staff_id', 'position_id', 'is_primary']
        );
        if ($staffPosition) {
            return t('org.staff_position_exists');
        }

        $staffPosition = $this->staffPositionRepository->create([
            'staff_id' => $staff_id,
            'position_id' => $position_id,
            'is_primary' => $is_primary,
        ]);

        if ($is_primary === 1) {
            $this->staffPositionRepository->updateAll(['staff_id' => $staff_id], ['is_primary' => 0]);
            return $this->staffPositionRepository->updateById($staffPosition->id, ['is_primary' => 1]);
        }

        return $staffPosition;
    }

    #[PostMapping]
    public function deleteAction(StaffPosition $staffPosition): StaffPosition
    {
        return $this->staffPositionRepository->delete($staffPosition);
    }
}
