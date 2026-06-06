<?php

declare(strict_types=1);

namespace App\Areas\Dict\Entity;

use App\Areas\Dict\CommonStatus;
use App\Areas\Dict\DictValueType;
use App\Entity\Entity;
use Switon\Dict\Attribute\DictValue;
use Switon\Orm\Attribute\Fillable;
use Switon\Orm\Attribute\FixedValue;
use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\Owner;
use Switon\Orm\Attribute\Table;
use Switon\Orm\Attribute\Unique;
use Switon\Validating\Attribute\Length;
use Switon\Validating\Attribute\MaxLength;
use Switon\Validating\Attribute\Range;

#[Table('dict_type')]
#[Owner(null)]
class Type extends Entity
{
    #[Id]
    public int $type_id;

    #[Length(2, 64), Unique, Fillable]
    public string $type_code;

    #[Length(2, 64), Fillable]
    public string $type_name;

    #[Length(1, 16), Fillable, DictValue(DictValueType::class)]
    public string $value_type;

    #[Range(0, 1), Fillable, FixedValue(0)]
    public int $builtin;

    #[Range(0, 1), Fillable, DictValue(CommonStatus::class)]
    public int $status;

    #[MaxLength(255), Fillable]
    public string $remark;

    #[Range(0, 65535), Fillable]
    public int $display_order;

    public string $created_by;
    public string $updated_by;
    public int $created_at;
    public int $updated_at;
}
