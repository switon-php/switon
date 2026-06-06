<?php

declare(strict_types=1);

namespace App\Areas\Org\Entity;

use App\Areas\Org\GroupType;
use App\Areas\Org\OrgStatus;
use App\Entity\Entity;
use Switon\Dict\Attribute\DictValue;
use Switon\Orm\Attribute\Fillable;
use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\Owner;
use Switon\Orm\Attribute\Table;
use Switon\Orm\Attribute\Unique;
use Switon\Validating\Attribute\Length;
use Switon\Validating\Attribute\Range;

#[Table('org_group')]
#[Owner(null)]
class Group extends Entity
{
    #[Id]
    public int $group_id;

    #[Length(2, 64), Unique, Fillable]
    public string $group_code;

    #[Length(2, 64), Fillable]
    public string $group_name;

    #[Length(0, 32), Fillable, DictValue(GroupType::class)]
    public string $type;

    #[Range(0, 65535), Fillable]
    public int $display_order;

    #[Range(0, 1), Fillable, DictValue(OrgStatus::class)]
    public int $status;

    public string $created_by;
    public string $updated_by;
    public int $created_at;
    public int $updated_at;
}
