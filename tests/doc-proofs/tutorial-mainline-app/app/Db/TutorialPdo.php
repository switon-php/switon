<?php

declare(strict_types=1);

namespace App\Db;

use PDO;
use function str_starts_with;
use function substr;

final class TutorialPdo extends PDO
{
    public function __construct(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        ?array $options = null,
    ) {
        if (str_starts_with($dsn, 'sqlite:sqlite:')) {
            $dsn = substr($dsn, 7);
        }

        parent::__construct($dsn, $username, $password, $options ?? []);
    }
}
