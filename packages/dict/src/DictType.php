<?php

declare(strict_types=1);

namespace Switon\Dict;

/**
 * Pure dict type value object.
 *
 * Use as the read-side result for one controlled value set.
 * The `builtin` flag marks whether the type comes from the built-in dict set.
 */
class DictType
{
    public function __construct(
        public string $typeCode,
        public string $typeName,
        public string $valueType = 'string',
        public bool   $builtin = true,
        public bool   $status = true,
    ) {
    }
}
