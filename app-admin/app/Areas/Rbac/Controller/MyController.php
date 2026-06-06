<?php

declare(strict_types=1);

namespace App\Areas\Rbac\Controller;

use App\Controller\Controller;
use App\Areas\Rbac\Repository\RoleRepository;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Authorizing\PermissionsInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

use function array_intersect;
use function array_merge;
use function array_unique;
use function array_values;
use function explode;
use function in_array;
use function sort;
use function trim;

#[Authorize(Authorize::AUTHENTICATED)]
#[RequestMapping('/rbac/my')]
class MyController extends Controller
{
    #[Autowired] protected PermissionsInterface $permissions;
    #[Autowired] protected RoleRepository $roleRepository;

    #[GetMapping]
    public function indexAction(): array
    {
        $roles = $this->identity->getRoles();
        $knownPermissions = [];
        $grantedPermissions = [];
        $permissionRoles = [Authorize::ANONYMOUS];
        if (!$this->identity->isGuest()) {
            $permissionRoles[] = Authorize::AUTHENTICATED;
            $permissionRoles = array_merge($permissionRoles, $roles);
        }

        foreach ($this->permissions->flatten($this->permissions->scanCatalog()) as $permissionRow) {
            $permission = trim((string)($permissionRow['permission_code'] ?? ''));
            if ($permission === '') {
                continue;
            }

            $knownPermissions[] = $permission;
            if (array_intersect($permissionRow['roles'] ?? [], $permissionRoles) !== []) {
                $grantedPermissions[] = $permission;
            }
        }

        if (!$this->identity->isGuest()) {
            foreach ($roles as $roleCode) {
                $rolePermissions = trim((string)$this->roleRepository->getPermissions((string)$roleCode), ',');
                if ($rolePermissions === '') {
                    continue;
                }

                foreach (explode(',', $rolePermissions) as $permission) {
                    $permission = trim((string)$permission);
                    if ($permission !== '') {
                        $grantedPermissions[] = $permission;
                    }
                }
            }
        }

        $knownPermissions = array_values(array_unique($knownPermissions));
        $grantedPermissions = array_values(array_unique($grantedPermissions));
        sort($knownPermissions);
        sort($grantedPermissions);

        return [
            'roles' => $roles,
            'is_superuser' => in_array(Authorize::SUPERUSER, $roles, true),
            'permissions' => $grantedPermissions,
            'known_permissions' => $knownPermissions,
        ];
    }
}
