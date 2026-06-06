<?php

declare(strict_types=1);

namespace App\Areas\Dict;

use Switon\Dict\Dict;

/**
 * Dict value type dictionary.
 */
class DictValueType extends Dict
{
    public static function typeCode(): ?string
    {
        return 'dict.value.type';
    }
}
