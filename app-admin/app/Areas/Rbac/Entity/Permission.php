<?php

declare(strict_types=1);

namespace App\Areas\Rbac\Entity;

use App\Entity\Entity;
use Switon\Orm\Attribute\Fillable;
use Switon\Orm\Attribute\HasManyToMany;
use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\Table;
use Switon\Validating\Attribute\MaxLength;

#[Table('rbac_permission')]
class Permission extends Entity
{
    #[Id]
    public int $permission_id;

    public string $permission_code;
    public string $authorize;
    public int $assignable;

    public int $derived;

    #[MaxLength(128), Fillable]
    public string $display_name;

    public int $created_at;
    public int $updated_at;

    /** @var array<Role> */
    #[HasManyToMany(RolePermission::class)]
    public array $roles;

    public function getId(): int
    {
        return $this->permission_id;
    }

    public function setId(int $id): void
    {
        $this->permission_id = $id;
    }

    public function getCode(): string
    {
        return $this->permission_code;
    }

    public function getAuthorize(): string
    {
        return $this->authorize;
    }

    public function isAssignable(): bool
    {
        return (bool)$this->assignable;
    }

    public function getDisplayName(): string
    {
        return $this->display_name;
    }

    public function isDerived(): bool
    {
        return $this->derived === 1;
    }
}
