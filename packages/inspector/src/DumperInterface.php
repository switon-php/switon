<?php

declare(strict_types=1);

namespace Switon\Inspector;

/**
 * Defines object dumping contract for inspector collectors.
 *
 * Use when runtime objects must be converted to JSON-safe arrays.
 *
 * @see \Switon\Inspector\Dumper
 */
interface DumperInterface
{
    /**
     * Returns raw object properties via reflection.
     *
     * @param object $object Object to inspect
     *
     * @return array<string, mixed> Property name-value pairs
     */
    public function getProperties(object $object): array;

    /**
     * Returns normalized object properties for inspector display.
     *
     * @param object $object Object to dump
     *
     * @return array<string, mixed> Normalized property name-value pairs
     */
    public function dump(object $object): array;
}
