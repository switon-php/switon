<?php

declare(strict_types=1);

namespace App\Areas\Rbac\Entity;

use App\Entity\Admin;
use App\Entity\Entity;
use Switon\Orm\Attribute\BelongsTo;
use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\Owner;
use Switon\Orm\Attribute\Table;
use Switon\Orm\Attribute\Unique;

#[Table('rbac_admin_role')]
#[Owner(null)]
class AdminRole extends Entity
{
    #[Id]
    public int $id;

    #[Unique(['role_id'])]
    public int $admin_id;

    public string $admin_name;
    public int $role_id;
    public string $role_code;
    public string $created_by;
    public int $created_at;

    #[BelongsTo]
    public ?Admin $admin;

    #[BelongsTo]
    public ?Role $role;

    public function getUserId(): int
    {
        return $this->admin_id;
    }

    public function getUserName(): string
    {
        return $this->admin_name;
    }

    public function getRoleId(): int
    {
        return $this->role_id;
    }

    public function getRoleName(): string
    {
        return $this->role_code;
    }
}
