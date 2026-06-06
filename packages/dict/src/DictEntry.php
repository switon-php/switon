<?php

declare(strict_types=1);

namespace Switon\Dict;

/**
 * Pure dict item value object.
 *
 * Use as the read-side result for one allowed item inside a dict type.
 * The `value` contract is stored in `itemValue`.
 */
final class DictEntry
{
    public function __construct(
        public string $typeCode,
        public string $itemCode,
        public string $itemName,
        public string $itemValue,
        public int    $tag = 0,
        public bool   $status = true,
        public bool   $isDefault = false,
    ) {
    }
}
