<?php

declare(strict_types=1);

use Switon\Core\App;
use Switon\Jwt\JwtInterface;

if (!function_exists('jwt_encode')) {
    /**
     * @param array<string, mixed> $claims
     */
    function jwt_encode(array $claims, int $ttl, ?string $kid = null): string
    {
        return App::get(JwtInterface::class)->encode($claims, $ttl, $kid);
    }
}

if (!function_exists('jwt_decode')) {
    /**
     * @return array<string, mixed>
     */
    function jwt_decode(string $token, ?string $kid = null): array
    {
        return App::get(JwtInterface::class)->decode($token, $kid);
    }
}
