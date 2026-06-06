<?php

declare(strict_types=1);

namespace Switon\Dict;

/**
 * Read normalized option rows derived from one dict type.
 *
 * Use this interface when application code needs stable option data without depending on dict item entities directly.
 *
 * @see \Switon\Dict\DictReaderInterface
 */
interface OptionReaderInterface
{
    /**
     * Return option rows for one readable dict type.
     *
     * Each row is normalized for form/select use and preserves the underlying dict code and tag.
     *
     * @return list<array{label: string, value: string, code: string, tag: int, default: bool}>
     */
    public function getOptions(string $typeCode, bool $builtin = true): array;
}
