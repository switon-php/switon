<?php

declare(strict_types=1);

namespace Switon\Orm\Tests\Fixtures;

use Switon\Orm\Attribute\BelongsTo;
use Switon\Orm\Entity;

/**
 * Junction entity with BelongsTo metadata for {@see JunctionManyRelation} field inference in tests.
 */
class TestRolePermissionJunction extends Entity
{
    public int $role_id;

    public int $permission_id;

    #[BelongsTo]
    public ?TestPermission $permission = null;

    #[BelongsTo]
    public ?TestRole $role = null;
}
