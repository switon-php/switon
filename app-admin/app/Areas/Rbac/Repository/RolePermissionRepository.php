<?php

declare(strict_types=1);

namespace App\Areas\Rbac\Repository;

use App\Areas\Rbac\Entity\RolePermission;
use Switon\Orm\JunctionRepositoryTrait;
use Switon\Orm\Repository;

/**
 * @extends Repository<RolePermission>
 */
class RolePermissionRepository extends Repository
{
    use JunctionRepositoryTrait;

    public function findPermissionIdsByRoleId(int $roleId): array
    {
        return $this->values(['role_id' => $roleId], 'permission_id');
    }
}
