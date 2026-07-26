<?php

declare(strict_types=1);

namespace App\Entity;

use Switon\Orm\Attribute\Column;
use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\Table;
use Switon\Orm\Entity;

#[Table('tasks')]
class Task extends Entity
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $title;

    #[Column]
    public ?string $note = null;

    #[Column]
    public bool $completed = false;
}
