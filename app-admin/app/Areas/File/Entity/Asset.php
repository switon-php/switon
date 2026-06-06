<?php

declare(strict_types=1);

namespace App\Areas\File\Entity;

use App\Areas\File\FileScene;
use App\Entity\Entity;
use Switon\Dict\Attribute\DictValue;
use Switon\Orm\Attribute\Fillable;
use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\Owner;
use Switon\Orm\Attribute\Table;
use Switon\Orm\Attribute\Unique;
use Switon\Validating\Attribute\Length;
use Switon\Validating\Attribute\Lowercase;
use Switon\Validating\Attribute\MaxLength;
use Switon\Validating\Attribute\Min;

#[Table('file_asset')]
#[Owner(null)]
class Asset extends Entity
{
    #[Id]
    public int $asset_id;

    #[Length(1, 32), Lowercase, DictValue(FileScene::class)]
    public string $scene;

    #[Length(1, 128), Fillable]
    public string $asset_name;

    #[Lowercase, MaxLength(16)]
    public string $asset_ext;

    #[MaxLength(128)]
    public string $mime_type;

    #[Min(0)]
    public int $asset_size;

    #[MaxLength(32)]
    public string $asset_hash;

    #[MaxLength(255), Unique]
    public string $asset_path;

    #[MaxLength(255)]
    public string $asset_url;

    #[MaxLength(255), Fillable]
    public string $remark;

    public string $created_by;
    public string $updated_by;
    public int $created_at;
    public int $updated_at;
}
