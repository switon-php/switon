<?php

declare(strict_types=1);

namespace App\Areas\Rbac\Repository;

use App\Areas\Rbac\Entity\Role;
use Switon\Authorizing\LookupInterface;
use Switon\Orm\Repository;

/**
 * @extends Repository<Role>
 */
class RoleRepository extends Repository implements LookupInterface
{
    public function getPermissions(string $role): ?string
    {
        return $this->value(['role_code' => $role], 'permissions');
    }
}
