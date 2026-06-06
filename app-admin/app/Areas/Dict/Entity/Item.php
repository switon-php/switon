<?php

declare(strict_types=1);

namespace App\Areas\Dict\Entity;

use App\Areas\Dict\CommonStatus;
use App\Entity\Entity;
use Switon\Dict\Attribute\DictValue;
use Switon\Orm\Attribute\BelongsTo;
use Switon\Orm\Attribute\Exists;
use Switon\Orm\Attribute\Fillable;
use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\Owner;
use Switon\Orm\Attribute\Table;
use Switon\Orm\Attribute\Unique;
use Switon\Validating\Attribute\Length;
use Switon\Validating\Attribute\MaxLength;
use Switon\Validating\Attribute\Min;
use Switon\Validating\Attribute\Range;

#[Table('dict_item')]
#[Owner(null)]
class Item extends Entity
{
    #[Id]
    public int $item_id;

    #[Exists(Type::class), Fillable]
    public int $type_id;

    #[Length(1, 64), Unique(['type_id']), Fillable]
    public string $item_code;

    #[Length(1, 64), Fillable]
    public string $item_name;

    #[Length(0, 128), Unique(['type_id']), Fillable]
    public string $item_value;

    #[Min(0), Fillable]
    public int $tag;

    #[Range(0, 1), Fillable, DictValue(CommonStatus::class)]
    public int $status;

    #[Range(0, 1), Fillable]
    public int $is_default;

    #[Range(0, 65535), Fillable]
    public int $display_order;

    #[MaxLength(255), Fillable]
    public string $remark;

    public string $created_by;
    public string $updated_by;
    public int $created_at;
    public int $updated_at;

    #[BelongsTo]
    public ?Type $type;
}
