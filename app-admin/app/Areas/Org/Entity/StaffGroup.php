<?php

declare(strict_types=1);

namespace App\Areas\Org\Entity;

use App\Entity\Entity;
use Switon\Orm\Attribute\Exists;
use Switon\Orm\Attribute\Fillable;
use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\Table;
use Switon\Orm\Attribute\Unique;
use Switon\Validating\Attribute\Min;

#[Table('org_staff_group')]
class StaffGroup extends Entity
{
    #[Id]
    public int $id;

    #[Min(1), Unique(['group_id']), Fillable]
    public int $staff_id;

    #[Exists(Group::class), Fillable]
    public int $group_id;

    public int $created_at;
}
