<?php

declare(strict_types=1);

namespace App\Areas\Rbac\Entity;

use App\Entity\Entity;
use Switon\Orm\Attribute\Fillable;
use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\Owner;
use Switon\Orm\Attribute\Table;
use Switon\Orm\Attribute\Unique;
use Switon\Validating\Attribute\Length;
use Switon\Validating\Attribute\Lowercase;
use Switon\Validating\Attribute\Trim;
use Switon\Validating\Attribute\Type;

#[Table('rbac_role')]
#[Owner(null)]
class Role extends Entity
{
    #[Id]
    public int $role_id;

    #[Lowercase, Length(3, 15), Unique, Fillable]
    public string $role_code;

    #[Trim, Lowercase, Length(3, 15), Unique, Fillable]
    public string $role_name;

    public int $builtin;

    #[Type('bit'), Fillable]
    public int $enabled;

    /** 缓存的权限 code 列表（逗号分隔）；库表默认空串，由 {@see \App\Areas\Rbac\Controller\PermissionController::rebuildAction}、{@see \App\Areas\Rbac\Controller\RolePermissionController::editAction} 等维护。 */
    public string $permissions;
    public string $created_by;
    public string $updated_by;
    public int $created_at;
    public int $updated_at;

    public function getId(): int
    {
        return $this->role_id;
    }

    public function getName(): string
    {
        return $this->role_code;
    }

    public function getDisplayName(): string
    {
        return $this->role_name;
    }

    public function isBuiltin(): bool
    {
        return (bool)$this->builtin;
    }

    public function isEnabled(): bool
    {
        return (bool)$this->enabled;
    }

    public function getPermissions(): string
    {
        return $this->permissions;
    }

    public function setPermissions(string $permissions): void
    {
        $this->permissions = $permissions;
    }
}
