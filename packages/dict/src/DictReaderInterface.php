<?php

declare(strict_types=1);

namespace Switon\Dict;

/**
 * Read dict types and items for parameter resolution and validation.
 *
 * Use this interface when one app or adapter owns dict existence, item lookup, and default-item lookup.
 *
 * Road-signs:
 * - DictResolver
 * - DictValue
 * - OptionReaderInterface for option rows
 *
 * @see \Switon\Dict\DictResolver
 * @see \Switon\Dict\Attribute\DictValue
 * @see \Switon\Dict\OptionReaderInterface
 */
interface DictReaderInterface
{
    /**
     * Return one readable dict type, or null when missing.
     */
    public function getType(string $typeCode, bool $builtin = true): ?DictType;

    /**
     * Return one dict item by stored value, or null when missing.
     *
     * The lookup value is compared against the stored item value representation.
     */
    public function getItem(string $typeCode, string|int|float|bool|null $value, bool $builtin = true): ?DictEntry;

    /**
     * Return the default dict item for one type, or null when none exists.
     */
    public function getDefaultItem(string $typeCode, bool $builtin = true): ?DictEntry;
}
