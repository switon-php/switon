<?php

declare(strict_types=1);

namespace App\Areas\Rbac\Controller;

use App\Areas\Rbac\Entity\Role;
use App\Areas\Rbac\Repository\PermissionRepository;
use App\Areas\Rbac\Repository\RolePermissionRepository;
use App\Areas\Rbac\Repository\RoleRepository;
use App\Controller\Controller;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Authorizing\PermissionsInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Attribute\Fields;
use Switon\Http\Attribute\Orders;
use Switon\Http\Criteria;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewGetMapping;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function implode;
use function trim;

#[Authorize]
#[RequestMapping('/rbac/role-permission')]
class RolePermissionController extends Controller
{
    #[Autowired] protected RoleRepository $roleRepository;
    #[Autowired] protected PermissionRepository $permissionRepository;
    #[Autowired] protected RolePermissionRepository $rolePermissionRepository;
    #[Autowired] protected PermissionsInterface $permissions;

    #[ViewGetMapping]
    public function indexAction(int $role_id = 0): array
    {
        if ($role_id <= 0) {
            return [];
        }

        $fields = ['permission_id', 'permission_code', 'display_name', 'created_at'];
        $role = $this->roleRepository->get($role_id);

        $permissionCodes = $this->cachedRolePermissionCodes($role);
        if ($permissionCodes === []) {
            return [];
        }

        $permissions = $this->permissionRepository->all(['permission_code' => $permissionCodes], $fields);
        $roleMapByCode = $this->roleMapByPermissionCode();
        foreach ($permissions as $permission) {
            $permission->roles = $roleMapByCode[$permission->permission_code] ?? [];
        }

        return $permissions;
    }

    #[PostMapping]
    public function editAction(int $role_id, array $permission_ids = []): void
    {
        $this->rolePermissionRepository->sync(Role::class, $role_id, $permission_ids);

        $role = $this->roleRepository->get($role_id);
        $permissionIds = $this->rolePermissionRepository->findPermissionIdsByRoleId($role_id);
        $granted = $permissionIds === []
            ? []
            : $this->permissionRepository->values(['permission_id' => $permissionIds], 'permission_code');
        $permissions = $this->permissions->expand($this->permissions->scanCatalog(), $role->getName(), $granted);
        $role->setPermissions(implode(',', $permissions));
        $this->roleRepository->update($role);
    }

    #[GetMapping]
    #[Fields(['role_id', 'role_code', 'role_name'])]
    public function rolesAction(Criteria $criteria): array
    {
        $criteria->filters['role_code!='] = [Authorize::ANONYMOUS, Authorize::AUTHENTICATED];

        return $this->roleRepository->all($criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[GetMapping]
    public function grantedAction(int $role_id): array
    {
        return $this->rolePermissionRepository->values(['role_id' => $role_id], 'permission_id');
    }

    /**
     * @return string[]
     */
    protected function cachedRolePermissionCodes(Role $role): array
    {
        $trimmed = trim($role->getPermissions(), ',');
        if ($trimmed === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $code): string => trim($code), explode(',', $trimmed)),
            static fn (string $code): bool => $code !== ''
        ));
    }

    /**
     * @return array<string, array<int, Role>>
     */
    protected function roleMapByPermissionCode(): array
    {
        $map = [];
        foreach ($this->roleRepository->all() as $role) {
            foreach ($this->cachedRolePermissionCodes($role) as $code) {
                $map[$code][] = $role;
            }
        }

        return $map;
    }
}
