<?php

declare(strict_types=1);

namespace App\Areas\Rbac;

use Switon\Dict\Dict;

/**
 * Admin status dictionary.
 */
class AdminStatus extends Dict
{
    public static function typeCode(): ?string
    {
        return 'admin.status';
    }
}
