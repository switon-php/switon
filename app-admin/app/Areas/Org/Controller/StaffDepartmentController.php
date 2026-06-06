<?php

declare(strict_types=1);

namespace App\Areas\Org\Controller;

use App\Areas\Org\Entity\StaffDepartment;
use App\Areas\Org\Repository\StaffDepartmentRepository;
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
#[RequestMapping('/org/staff-department')]
class StaffDepartmentController extends Controller
{
    #[Autowired] protected StaffDepartmentRepository $staffDepartmentRepository;

    #[ViewGetMapping]
    #[Filters(['id', 'staff_id', 'department_id'])]
    #[Orders(['id' => SORT_DESC])]
    public function indexAction(Page $page, Criteria $criteria): Paginator
    {
        return $this->staffDepartmentRepository->paginate($page, $criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[PostMapping]
    public function editAction(StaffDepartment $staffDepartment): StaffDepartment
    {
        if ($staffDepartment->is_primary === 1) {
            $this->staffDepartmentRepository->updateAll(['staff_id' => $staffDepartment->staff_id], ['is_primary' => 0]);
        }

        return $this->staffDepartmentRepository->update($staffDepartment);
    }

    #[PostMapping]
    public function createAction(
        int $staff_id,
        int $department_id,
        #[Type('bit')] int $is_primary = 0
    ): string|StaffDepartment {
        $staffDepartment = $this->staffDepartmentRepository->first(
            ['staff_id' => $staff_id, 'department_id' => $department_id],
            ['id', 'staff_id', 'department_id', 'is_primary']
        );
        if ($staffDepartment) {
            return t('org.staff_department_exists');
        }

        $staffDepartment = $this->staffDepartmentRepository->create([
            'staff_id' => $staff_id,
            'department_id' => $department_id,
            'is_primary' => $is_primary,
        ]);

        if ($is_primary === 1) {
            $this->staffDepartmentRepository->updateAll(['staff_id' => $staff_id], ['is_primary' => 0]);
            return $this->staffDepartmentRepository->updateById($staffDepartment->id, ['is_primary' => 1]);
        }

        return $staffDepartment;
    }

    #[PostMapping]
    public function deleteAction(StaffDepartment $staffDepartment): StaffDepartment
    {
        return $this->staffDepartmentRepository->delete($staffDepartment);
    }
}
