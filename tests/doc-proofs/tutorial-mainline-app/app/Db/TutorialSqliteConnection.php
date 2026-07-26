<?php

declare(strict_types=1);

namespace App\Db;

use Switon\Db\Connection\Adapter\Sqlite;
use function str_starts_with;
use function substr;

class TutorialSqliteConnection extends Sqlite
{
    public function __construct()
    {
        if (str_starts_with($this->uri, 'sqlite:')) {
            $this->uri = substr($this->uri, 7);
        }

        parent::__construct();
    }
}
