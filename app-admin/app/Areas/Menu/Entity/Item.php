<?php

declare(strict_types=1);

namespace App\Areas\Menu\Entity;

use App\Entity\Entity;
use Switon\Orm\Attribute\Exists;
use Switon\Orm\Attribute\Fillable;
use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\Owner;
use Switon\Orm\Attribute\Table;
use Switon\Orm\Attribute\Unique;
use Switon\Validating\Attribute\Length;
use Switon\Validating\Attribute\MaxLength;
use Switon\Validating\Attribute\Range;

#[Table('menu_item')]
#[Owner(null)]
class Item extends Entity
{
    #[Id]
    public int $item_id;

    #[Length(2, 32), Unique(['group_id']), Fillable]
    public string $item_name;

    #[Exists(Group::class), Fillable]
    public int $group_id;

    #[Range(0, 127), Fillable]
    public int $display_order;

    #[Length(1, 128), Unique(['group_id']), Fillable]
    public string $url;

    #[MaxLength(64), Fillable]
    public string $icon;

    #[MaxLength(64), Fillable]
    public string $permission_code;

    public string $created_by;
    public string $updated_by;
    public int $created_at;
    public int $updated_at;
}
