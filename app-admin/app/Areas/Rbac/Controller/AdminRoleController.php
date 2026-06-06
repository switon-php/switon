<?php

declare(strict_types=1);

namespace App\Areas\Rbac\Controller;

use App\Controller\Controller;
use App\Entity\Admin;
use App\Repository\AdminRepository;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Attribute\Fields;
use Switon\Http\Attribute\Keyword;
use Switon\Http\Attribute\Orders;
use Switon\Http\Criteria;
use Switon\Orm\Page;
use Switon\Query\Paginator;
use App\Areas\Rbac\Repository\AdminRoleRepository;
use App\Areas\Rbac\Repository\RoleRepository;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewGetMapping;

#[Authorize]
#[RequestMapping('/rbac/admin-role')]
class AdminRoleController extends Controller
{
    #[Autowired] protected AdminRepository $adminRepository;
    #[Autowired] protected RoleRepository $roleRepository;
    #[Autowired] protected AdminRoleRepository $adminRoleRepository;

    #[ViewGetMapping]
    #[Fields(['admin_id', 'admin_name', 'created_at', 'roles' => ['role_id', 'role_name']])]
    #[Orders(['admin_id' => SORT_DESC])]
    #[Keyword(['admin_name'])]
    public function indexAction(Page $page, Criteria $criteria, int $role_id = 0): Paginator
    {
        if ($role_id > 0) {
            $criteria->filters['admin_id'] = $this->adminRoleRepository->values(['role_id' => $role_id], 'admin_id');
        }

        return $this->adminRepository->paginate($page, $criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[GetMapping]
    public function detailAction(int $admin_id): array
    {
        return $this->adminRoleRepository->all(['admin_id' => $admin_id]);
    }

    #[PostMapping]
    public function editAction(int $admin_id, array $role_ids = []): void
    {
        $this->adminRoleRepository->sync(Admin::class, $admin_id, $role_ids);
    }

    #[GetMapping]
    #[Fields(['role_id', 'role_name'])]
    public function rolesAction(Criteria $criteria): array
    {
        $criteria->filters['role_code!='] = [Authorize::ANONYMOUS, Authorize::AUTHENTICATED];
        return $this->roleRepository->all($criteria->filters, $criteria->fields, $criteria->orders);
    }
}
