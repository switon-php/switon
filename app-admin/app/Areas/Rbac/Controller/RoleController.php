<?php

declare(strict_types=1);

namespace App\Areas\Rbac\Controller;

use App\Areas\Rbac\Entity\Role;
use App\Areas\Rbac\Repository\AdminRoleRepository;
use App\Areas\Rbac\Repository\PermissionRepository;
use App\Areas\Rbac\Repository\RolePermissionRepository;
use App\Areas\Rbac\Repository\RoleRepository;
use App\Controller\Controller;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Authorizing\PermissionsInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Attribute\Fields;
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

use function implode;

#[Authorize]
#[RequestMapping('/rbac/role')]
class RoleController extends Controller
{
    #[Autowired] protected RoleRepository $roleRepository;
    #[Autowired] protected AdminRoleRepository $adminRoleRepository;
    #[Autowired] protected RolePermissionRepository $rolePermissionRepository;
    #[Autowired] protected PermissionRepository $permissionRepository;
    #[Autowired] protected PermissionsInterface $permissions;

    #[ViewGetMapping]
    #[Orders(['role_id' => SORT_DESC])]
    #[Keyword(['role_code', 'role_name'])]
    public function indexAction(Page $page, Criteria $criteria): Paginator
    {
        return $this->roleRepository->paginate($page, $criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[GetMapping]
    #[Fields(['role_id', 'role_name'])]
    public function listAction(Criteria $criteria): array
    {
        return $this->roleRepository->all($criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[PostMapping]
    public function createAction(RequestInterface $request): Role
    {
        $role = $this->roleRepository->fill($request->all());
        $role->builtin = 0;
        $role = $this->roleRepository->create($role);

        $permissionIds = $this->rolePermissionRepository->findPermissionIdsByRoleId($role->role_id);
        $granted = $permissionIds === []
            ? []
            : $this->permissionRepository->values(['permission_id' => $permissionIds], 'permission_code');
        $permissions = $this->permissions->expand(
            $this->permissions->scanCatalog(),
            $role->getName(),
            $granted
        );
        $role->permissions = implode(',', $permissions);

        return $this->roleRepository->update($role);
    }

    #[PostMapping]
    public function editAction(Role $role): Role
    {
        return $this->roleRepository->update($role);
    }

    #[PostMapping]
    public function disableAction(int $role_id): Role
    {
        return $this->roleRepository->updateById($role_id, ['enabled' => 0]);
    }

    #[PostMapping]
    public function enableAction(int $role_id): Role
    {
        return $this->roleRepository->updateById($role_id, ['enabled' => 1]);
    }

    #[GetMapping]
    public function detailAction(Role $role): Role
    {
        return $role;
    }

    #[PostMapping]
    public function deleteAction(Role $role): string|Role
    {
        if ($this->adminRoleRepository->exists(['role_id' => $role->role_id])) {
            return t('rbac.delete_role_bound_users');
        }

        if ($role->builtin) {
            return t('rbac.delete_builtin_role_forbidden');
        }

        $this->rolePermissionRepository->deleteAll(['role_id' => $role->role_id]);
        return $this->roleRepository->delete($role);
    }
}
