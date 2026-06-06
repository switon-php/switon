<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request\CreateTaskRequest;
use App\Http\Request\TaskFilterQuery;
use App\Service\TaskServiceInterface;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Controller;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\PutMapping;
use Switon\Routing\Attribute\RequestMapping;

#[Authorize(Authorize::ANONYMOUS)]
#[RequestMapping('/tasks')]
class TaskController extends Controller
{
    #[Autowired] protected TaskServiceInterface $taskService;

    #[GetMapping('')]
    public function listAction(TaskFilterQuery $query): array
    {
        return [
            'filter' => ['completed' => $query->completed],
            'tasks' => $this->taskService->list($query->completed),
        ];
    }

    #[PostMapping('')]
    public function createAction(CreateTaskRequest $request): array
    {
        return [
            'task' => $this->taskService->create($request->title, $request->note),
        ];
    }

    #[PutMapping('{id}/complete')]
    public function completeAction(int $id): array
    {
        return [
            'task' => $this->taskService->complete($id),
        ];
    }
}
