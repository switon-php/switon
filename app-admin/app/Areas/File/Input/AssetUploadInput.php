<?php

declare(strict_types=1);

namespace App\Areas\File\Input;

use App\Areas\File\FileScene;
use Switon\Http\Attribute\RequestBody;
use Switon\Dict\Attribute\DictValue;
use Switon\Validating\Attribute\MaxLength;
use Switon\Validating\Attribute\Trim;

#[RequestBody]
/**
 * Upload metadata input.
 */
class AssetUploadInput
{
    #[Trim]
    #[MaxLength(32)]
    #[DictValue(FileScene::class)]
    public string $scene = 'asset';

    #[Trim]
    #[MaxLength(128)]
    public string $asset_name = '';

    #[Trim]
    #[MaxLength(255)]
    public string $remark = '';
}
