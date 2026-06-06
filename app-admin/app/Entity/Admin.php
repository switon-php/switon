<?php

declare(strict_types=1);

namespace App\Entity;

use App\Areas\File\Entity\Asset;
use App\Areas\Rbac\AdminStatus;
use App\Areas\Rbac\Entity\AdminRole;
use App\Areas\Rbac\Entity\Role;
use App\Repository\AdminRepository;
use Psr\Container\ContainerInterface;
use Switon\Dict\Attribute\DictValue;
use Switon\Binding\ArgumentResolvable;
use Switon\Orm\Attribute\BelongsTo;
use Switon\Orm\Attribute\HasManyToMany;
use Switon\Orm\Attribute\Fillable;
use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\Immutable;
use Switon\Orm\Attribute\Owner;
use Switon\Orm\Attribute\Unique;
use Switon\Orm\Event\EntityCreating;
use Switon\Orm\Event\EntityEventInterface;
use Switon\Orm\Event\EntityUpdating;
use Switon\Principal\IdentityInterface;
use Switon\Validating\Attribute\Account;
use Switon\Validating\Attribute\Defaults;
use Switon\Validating\Attribute\Email;
use Switon\Validating\Attribute\Length;
use Switon\Validating\Attribute\MaxLength;
use Switon\Validating\Attribute\Min;

#[Owner(null)]
class Admin extends Entity implements ArgumentResolvable
{
    public const STATUS_INIT = 0;
    public const STATUS_ACTIVE = 1;
    public const STATUS_LOCKED = 2;
    protected const PASSWORD_BCRYPT_COST = 8;

    #[Id]
    public int $admin_id;

    #[Length(4, 16), Account, Immutable, Unique, Fillable]
    public string $admin_name;

    #[Fillable, DictValue(AdminStatus::class)]
    public int $status;

    public int $type;
    public int $tag;

    #[Email, Unique, Fillable]
    public string $email;

    #[Min(0), Fillable]
    public int $avatar_id;

    #[Length(6, 32), Fillable]
    public string $password;

    #[Defaults(''), MaxLength(64), Fillable]
    public string $white_ip;

    public string $login_ip;
    public int $login_time;
    public string $session_id;
    public int $fail_count;
    public int $failed_at;
    public int $locked_until;
    public string $created_by;
    public string $updated_by;
    public int $created_at;
    public int $updated_at;

    /** @var array<Role> */
    #[HasManyToMany(AdminRole::class, foreignEntity: Role::class, orderBy: ['role_id' => SORT_ASC])]
    public array $roles;

    #[BelongsTo('avatar_id')]
    public ?Asset $avatar;

    public static function argumentResolve(ContainerInterface $container): mixed
    {
        $identity = $container->get(IdentityInterface::class);
        return $container->get(AdminRepository::class)->get($identity->getId());
    }

    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => static::PASSWORD_BCRYPT_COST]);
    }

    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password);
    }

    public function isTemporarilyLocked(int $now): bool
    {
        return $this->locked_until > $now;
    }

    public function onEvent(EntityEventInterface $entityEvent): void
    {
        if ($entityEvent instanceof EntityCreating
            || ($entityEvent instanceof EntityUpdating
                && $entityEvent->hasChanged(['password']))
        ) {
            $this->password = $this->hashPassword($this->password);
        }
    }
}
