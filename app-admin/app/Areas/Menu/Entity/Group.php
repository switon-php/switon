<?php

declare(strict_types=1);

namespace App\Areas\Menu\Entity;

use App\Entity\Entity;
use Switon\Orm\Attribute\Fillable;
use Switon\Orm\Attribute\HasMany;
use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\Owner;
use Switon\Orm\Attribute\Table;
use Switon\Orm\Attribute\Unique;
use Switon\Validating\Attribute\MaxLength;
use Switon\Validating\Attribute\Range;

#[Table('menu_group')]
#[Owner(null)]
class Group extends Entity
{
    #[Id]
    public int $group_id;

    #[Unique, Fillable]
    public string $group_name;

    #[MaxLength(64), Fillable]
    public string $icon;

    #[Range(0, 127), Fillable]
    public int $display_order;

    public string $created_by;
    public string $updated_by;
    public int $created_at;
    public int $updated_at;

    /** @var Item[] $items */
    #[HasMany(Item::class, orderBy: ['display_order' => SORT_DESC, 'item_id' => SORT_ASC])]
    public array $items;
}
