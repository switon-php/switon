<?php

declare(strict_types=1);

namespace App\Areas\Dict;

use Switon\Dict\Dict;

/**
 * Common status dictionary.
 */
class CommonStatus extends Dict
{
    public static function typeCode(): ?string
    {
        return 'common.status';
    }
}
