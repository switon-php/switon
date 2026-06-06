<?php

declare(strict_types=1);

namespace App\Areas\Org\Controller;

use App\Areas\Org\Entity\Department;
use App\Areas\Org\Repository\DepartmentRepository;
use App\Controller\Controller;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Attribute\Fields;
use Switon\Http\Attribute\Filters;
use Switon\Http\Attribute\Keyword;
use Switon\Http\Attribute\Orders;
use Switon\Http\Criteria;
use Switon\Http\RequestInterface;
use Switon\Orm\Page;
use Switon\Query\Paginator;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewGetMapping;

#[Authorize]
#[RequestMapping('/org/department')]
class DepartmentController extends Controller
{
    #[Autowired] protected DepartmentRepository $departmentRepository;

    #[ViewGetMapping]
    #[Filters(['department_id', 'department_code', 'department_name', 'parent_id', 'display_order', 'status'])]
    #[Orders(['display_order' => SORT_DESC, 'department_id' => SORT_ASC])]
    #[Keyword(['department_code', 'department_name'])]
    public function indexAction(Page $page, Criteria $criteria): Paginator
    {
        return $this->departmentRepository->paginate($page, $criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[GetMapping]
    #[Fields(['department_id', 'department_name'])]
    public function listAction(Criteria $criteria): array
    {
        return $this->departmentRepository->all($criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[PostMapping]
    public function createAction(RequestInterface $request): Department
    {
        return $this->departmentRepository->create($request->all());
    }

    #[PostMapping]
    public function editAction(Department $department): Department
    {
        return $this->departmentRepository->update($department);
    }

    #[PostMapping]
    public function deleteAction(Department $department): Department
    {
        return $this->departmentRepository->delete($department);
    }
}
