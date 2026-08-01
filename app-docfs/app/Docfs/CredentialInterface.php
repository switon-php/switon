<?php

declare(strict_types=1);

namespace App\Docfs;

/**
 * Session access key → JWT payload (any failure raises).
 */
interface CredentialInterface
{
    public const PERM_READ = 'read';
    public const PERM_WRITE = 'write';

    /**
     * @return array<string, mixed> JWT claims (e.g. `books` map)
     */
    public function payload(): array;
}
