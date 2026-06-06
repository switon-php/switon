<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Task;
use App\Repository\TaskRepository;
use Switon\Core\Attribute\Autowired;

final class TaskService implements TaskServiceInterface
{
    #[Autowired] protected TaskRepository $taskRepository;

    public function list(?bool $completed = null): array
    {
        $filters = [];
        if ($completed !== null) {
            $filters['completed'] = $completed;
        }

        return array_map(
            fn (Task $task): array => $this->toPayload($task),
            $this->taskRepository->all($filters),
        );
    }

    public function create(string $title, ?string $note = null): array
    {
        $task = new Task();
        $task->title = $title;
        $task->note = $note;
        $task->completed = false;

        $task = $this->taskRepository->create($task);

        return $this->toPayload($task);
    }

    public function complete(int $id): array
    {
        $task = $this->taskRepository->find($id);
        if (!$task instanceof Task) {
            return [
                'id' => $id,
                'completed' => false,
                'found' => false,
            ];
        }

        $task->completed = true;
        $task = $this->taskRepository->update($task);

        return $this->toPayload($task) + ['found' => true];
    }

    /** @return array<string, mixed> */
    protected function toPayload(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'note' => $task->note,
            'completed' => (bool) $task->completed,
        ];
    }
}
