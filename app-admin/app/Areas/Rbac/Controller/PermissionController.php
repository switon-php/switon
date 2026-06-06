<?php

declare(strict_types=1);

namespace App\Areas\Rbac\Controller;

use App\Areas\Rbac\Entity\Permission;
use App\Areas\Rbac\Entity\Role;
use App\Areas\Rbac\Repository\PermissionRepository;
use App\Areas\Rbac\Repository\RolePermissionRepository;
use App\Areas\Rbac\Repository\RoleRepository;
use App\Controller\Controller;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Authorizing\BuiltinRolesInterface;
use Switon\Authorizing\PermissionsInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Attribute\Fields;
use Switon\Http\Attribute\Filters;
use Switon\Http\Attribute\Keyword;
use Switon\Http\Attribute\Orders;
use Switon\Http\Criteria;
use Switon\Http\ResponseInterface;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\Attribute\ViewGetMapping;
use RuntimeException;

use function explode;
use function implode;
use function ksort;
use function str_contains;

#[Authorize]
#[RequestMapping('/rbac/permission')]
class PermissionController extends Controller
{
    #[Autowired] protected PermissionRepository $permissionRepository;
    #[Autowired] protected RolePermissionRepository $rolePermissionRepository;
    #[Autowired] protected RoleRepository $roleRepository;
    #[Autowired] protected BuiltinRolesInterface $builtinRoles;
    #[Autowired] protected PermissionsInterface $permissions;
    #[ViewGetMapping]
    #[Filters(['derived'])]
    #[Keyword(['permission_code', 'display_name'], 'keyword', '*=')]
    #[Fields(['permission_id', 'permission_code', 'display_name', 'authorize', 'assignable', 'derived', 'updated_at',
        'roles' => ['role_id', 'role_name'],
    ])]
    #[Orders(['permission_id' => SORT_DESC])]
    public function indexAction(Criteria $criteria): array
    {
        return $this->permissionRepository->all($criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[GetMapping]
    #[Fields(['permission_id', 'permission_code', 'display_name'])]
    #[Orders(['permission_code' => SORT_ASC])]
    public function listAction(Criteria $criteria): array
    {
        return $this->permissionRepository->all($criteria->filters, $criteria->fields, $criteria->orders);
    }

    #[GetMapping]
    public function catalogAction(int $assignable_only = 0): array
    {
        return [
            'groups' => $this->groupCatalogByController($assignable_only === 1),
            'last_scan_at' => $this->lastScanAt(),
        ];
    }

    #[GetMapping]
    public function lastScanAction(): array
    {
        return ['last_scan_at' => $this->lastScanAt()];
    }

    #[PostMapping]
    public function rebuildAction(): ResponseInterface
    {
        $scanResult = $this->permissions->scanCatalog();
        $counts = $this->rebuildPermissionCatalog($scanResult);

        foreach ($this->builtinRoles->all() as $roleName) {
            if (!$this->roleRepository->exists(['role_code' => $roleName])) {
                $role = new Role();
                $role->role_code = $roleName;
                $role->role_name = $roleName;
                $role->enabled = 1;
                $role->builtin = 1;
                $this->roleRepository->create($role);
            }
        }

        $this->refreshRolePermissionCache($scanResult);

        return $this->response->json([
            'code' => 0,
            'msg' => t('admin.permission_rebuild_result', [
                'create' => $counts['create'],
                'delete' => $counts['delete'],
                'update' => $counts['update'],
            ]),
            'last_scan_at' => $this->lastScanAt(),
        ]);
    }

    #[PostMapping]
    public function editAction(Permission $permission): Permission
    {
        return $this->permissionRepository->update($permission);
    }

    #[PostMapping]
    public function deleteAction(Permission $permission): Permission
    {
        $this->rolePermissionRepository->deleteAll(['permission_id' => $permission->permission_id]);

        return $this->permissionRepository->delete($permission);
    }

    /**
     * @return array{create:int,update:int,delete:int}
     */
    protected function rebuildPermissionCatalog(array $scanResult): array
    {
        $oldPermissions = [];
        foreach ($this->permissionRepository->all() as $permission) {
            $oldPermissions[$permission->getCode()] = $permission;
        }

        $newPermissions = [];
        foreach ($this->permissions->flatten($scanResult) as $item) {
            $permissionCode = $item['permission_code'];
            $permission = new Permission();
            $permission->permission_code = $permissionCode;
            $existing = $oldPermissions[$permissionCode] ?? null;
            $permission->display_name = $existing !== null
                ? $existing->getDisplayName()
                : (string)$permissionCode;
            $permission->authorize = implode(',', $item['roles']);
            $permission->assignable = (int)$item['assignable'];
            $permission->derived = ((bool)$item['inherited'] === true) ? 1 : 0;
            $newPermissions[$permissionCode] = $permission;
        }

        $deletedCount = 0;
        foreach ($oldPermissions as $permissionCode => $permission) {
            if (!isset($newPermissions[$permissionCode])) {
                $deletedCount++;
                $this->permissionRepository->delete($permission);
            }
        }

        $createdCount = 0;
        $updatedCount = 0;
        foreach ($newPermissions as $permissionCode => $permission) {
            if (($oldPermission = $oldPermissions[$permissionCode] ?? null) === null) {
                $createdCount++;
                $this->permissionRepository->create($permission);
                continue;
            }

            if (
                $permission->getDisplayName() !== $oldPermission->getDisplayName()
                || $permission->getAuthorize() !== $oldPermission->getAuthorize()
                || $permission->isAssignable() !== $oldPermission->isAssignable()
                || $permission->isDerived() !== $oldPermission->isDerived()
            ) {
                $updatedCount++;
                $permission->setId($oldPermission->getId());
                $this->permissionRepository->update($permission);
            }
        }

        return ['create' => $createdCount, 'update' => $updatedCount, 'delete' => $deletedCount];
    }

    protected function refreshRolePermissionCache(array $scanResult): void
    {
        $permissionCodeById = [];
        foreach ($this->permissionRepository->all([], ['permission_id', 'permission_code']) as $permission) {
            $permissionCodeById[(int)$permission->permission_id] = (string)$permission->permission_code;
        }

        $grantedByRoleId = [];
        foreach ($this->rolePermissionRepository->all([], ['role_id', 'permission_id']) as $row) {
            $permissionCode = $permissionCodeById[(int)$row->permission_id] ?? null;
            if ($permissionCode === null) {
                continue;
            }

            $grantedByRoleId[(int)$row->role_id][] = $permissionCode;
        }

        foreach ($this->roleRepository->all() as $role) {
            $granted = $grantedByRoleId[$role->getId()] ?? [];
            $permissions = $this->permissions->expand($scanResult, $role->getName(), $granted);
            $role->setPermissions(implode(',', $permissions));
            $this->roleRepository->update($role);
        }
    }

    /**
     * @return array<string, array{class: string, wildcard: ?array<string, mixed>, actions: array<string, array<string, mixed>>}>
     */
    protected function groupCatalogByController(bool $assignableOnly): array
    {
        $groups = [];

        foreach ($this->permissionRepository->all() as $permissionEntity) {
            $permission = $permissionEntity->toArray();
            $assignable = (int)$permission['assignable'];
            if ($assignableOnly && $assignable !== 1) {
                continue;
            }

            $code = (string)$permission['permission_code'];
            if (!str_contains($code, '::')) {
                throw new RuntimeException("Invalid permission_code in rbac_permission: {$code}");
            }
            [$controllerId, $action] = explode('::', $code, 2);
            if (!isset($groups[$controllerId])) {
                $groups[$controllerId] = ['class' => '', 'wildcard' => null, 'actions' => []];
            }

            $leaf = $permission;
            $leaf['action'] = $action;
            $leaf['source'] = null;
            $leaf['assignable_explicit'] = 0;

            if ($action === '*') {
                $groups[$controllerId]['wildcard'] = $leaf;
            } else {
                $groups[$controllerId]['actions'][$action] = $leaf;
            }
        }

        foreach ($groups as &$group) {
            ksort($group['actions']);
        }
        unset($group);

        ksort($groups);
        return $groups;
    }

    protected function lastScanAt(): int
    {
        $latest = $this->permissionRepository->all([], ['permission_id', 'created_at'], ['permission_id' => SORT_DESC])[0]
            ?? null;

        return $latest?->created_at ?? 0;
    }
}
