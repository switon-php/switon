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
use Switon\Validating\Attribute\Range;

#[Table('org_staff_position')]
class StaffPosition extends Entity
{
    #[Id]
    public int $id;

    #[Min(1), Unique(['position_id']), Fillable]
    public int $staff_id;

    #[Exists(Position::class), Fillable]
    public int $position_id;

    #[Range(0, 1), Fillable]
    public int $is_primary;

    public int $created_at;
}
