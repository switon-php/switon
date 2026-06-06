<?php

declare(strict_types=1);

namespace App\Areas\Org;

use Switon\Dict\Dict;

/**
 * Organization status dictionary.
 */
class OrgStatus extends Dict
{
    public static function typeCode(): ?string
    {
        return 'org.status';
    }
}
