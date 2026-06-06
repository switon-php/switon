<?php

declare(strict_types=1);

namespace Switon\Captcha;

/**
 * Contract for persisting CAPTCHA payloads by challenge key.
 *
 * Guidance: Store must round-trip code, created_at, ttl, and optional attempts exactly; verify() depends on them.
 *
 * Road-signs:
 * - get() returns nullable payload
 * - put() persists one challenge payload
 * - forget() removes one challenge payload
 *
 * @see \Switon\Captcha\Store
 * @see \Switon\Captcha\AbstractCaptcha
 */
interface StoreInterface
{
    /**
     * Gets stored CAPTCHA payload by key.
     *
     * @param string $key Captcha key
     *
     * @return array{code: string, created_at: float|int, ttl: int, attempts?: int}|null Payload or null when not found
     */
    public function get(string $key): ?array;

    /**
     * Stores CAPTCHA payload with TTL.
     *
     * @param string $key Captcha key
     * @param array{code: string, created_at: float|int, ttl: int, attempts?: int} $payload Payload to persist
     * @param int $ttl Time to live in seconds
     */
    public function put(string $key, array $payload, int $ttl): void;

    /**
     * Removes stored CAPTCHA payload.
     *
     * @param string $key Captcha key
     */
    public function forget(string $key): void;
}
