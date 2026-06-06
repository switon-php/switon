<?php

declare(strict_types=1);

namespace App\Service;

interface TaskServiceInterface
{
    /** @return array<int, array<string, mixed>> */
    public function list(?bool $completed = null): array;

    /** @return array<string, mixed> */
    public function create(string $title, ?string $note = null): array;

    /** @return array<string, mixed> */
    public function complete(int $id): array;
}
