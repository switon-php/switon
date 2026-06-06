<?php

declare(strict_types=1);

namespace App\Areas\Rbac;

use Switon\Dict\Dict;

/**
 * Permission derived-type dictionary.
 */
class PermissionDerived extends Dict
{
    public static function typeCode(): ?string
    {
        return 'rbac.permission.derived';
    }
}
