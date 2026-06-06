<?php

declare(strict_types=1);

namespace App\Areas\Org;

use Switon\Dict\Dict;

/**
 * Organization group type dictionary.
 */
class GroupType extends Dict
{
    public static function typeCode(): ?string
    {
        return 'org.group.type';
    }
}
