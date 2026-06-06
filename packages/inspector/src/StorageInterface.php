<?php

declare(strict_types=1);

namespace Switon\Inspector;

/**
 * Defines persistence contract for inspector payload storage.
 *
 * Use when inspector data is written at request end and read by render endpoints.
 *
 * @see \Switon\Inspector\Storage
 */
interface StorageInterface
{
    /**
     * Reads inspector data by key.
     *
     * @param string $key Inspector key
     *
     * @return string|null JSON payload, or null if not found
     */
    public function read(string $key): ?string;

    /**
     * Writes inspector data.
     *
     * @param string $key Inspector key
     * @param array<string, mixed> $data Data payload to store
     */
    public function write(string $key, array $data): void;

    /**
     * Broadcasts generated inspector link.
     *
     * @param string $link Inspector link URL
     */
    public function broadcastLink(string $link): void;
}
