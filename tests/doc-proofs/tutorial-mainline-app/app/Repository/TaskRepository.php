<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
use Switon\Orm\Repository;

/** @extends Repository<Task> */
final class TaskRepository extends Repository
{
    protected string $entityClass = Task::class;
}
