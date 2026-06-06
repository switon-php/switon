<?php

declare(strict_types=1);

namespace App\Areas\Rbac\Entity;

use App\Entity\Entity;
use Switon\Orm\Attribute\BelongsTo;
use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\JunctionMany;
use Switon\Orm\Attribute\Owner;
use Switon\Orm\Attribute\Table;

#[Table('rbac_role_permission')]
#[Owner(null)]
class RolePermission extends Entity
{
    #[Id]
    public int $id;

    public int $role_id;
    public int $permission_id;
    public string $created_by;
    public int $created_at;

    #[BelongsTo]
    public ?Permission $permission;

    #[BelongsTo]
    public ?Role $role;

    #[JunctionMany(orderBy: ['role_id' => SORT_ASC])]
    public array $roles;
}
