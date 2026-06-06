<?php

declare(strict_types=1);

namespace App\Areas\File;

use Switon\Dict\Dict;

/**
 * File upload scene dictionary.
 */
class FileScene extends Dict
{
    public static function typeCode(): ?string
    {
        return 'file.scene';
    }
}
