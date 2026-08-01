---
sidebar_label: Service Layer
description: Move task business logic out of controllers and into reusable services.
---

# 03 — Service boundary {#03-service}

## Goal {#goal}

Move business logic from controllers into a reusable service layer.

**Primary package:** [DI](../../packages/di/docs/README.md#quick-start)

## Code {#shape}

`TaskServiceInterface`, `TaskService`, and `CreateTaskRequest` are illustrative names.
Create `app/Service/TaskServiceInterface.php` and `app/Service/TaskService.php`, then replace `app/Controller/TaskController.php` with the controller below.

```php
namespace App\Service;

interface TaskServiceInterface
{
    public function list(?bool $completed = null): array;

    public function create(string $title, ?string $note = null): array;

    public function complete(int $id): array;
}

final class TaskService implements TaskServiceInterface
{
    public function list(?bool $completed = null): array
    {
        return [
            ['id' => 1, 'title' => 'Read the tutorial', 'completed' => false],
        ];
    }

    public function create(string $title, ?string $note = null): array
    {
        return [
            'id' => 2,
            'title' => $title,
            'note' => $note,
            'completed' => false,
        ];
    }

    public function complete(int $id): array
    {
        return [
            'id' => $id,
            'completed' => true,
        ];
    }
}
```

```php
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
```

This chapter does not cover persistence yet.
Controller handles transport input. Service handles business rules.

## Steps {#steps}

1. Extract one business action first, usually `create()` or `complete()`.
2. Depend on an interface, not a concrete service class.
3. Keep controllers responsible for HTTP only.

Use the default same-namespace convention:

- `TaskServiceInterface`
- `TaskService`

Under `App\Service\TaskServiceInterface` -> `App\Service\TaskService`, DI can auto-map without extra binding config.
If class names diverge or you add multiple implementations, use explicit DI config.

Controller responsibilities:

- receive request input objects
- call the service with application values
- shape the response

Service responsibilities:

- apply business rules
- return application-level results

## Verify {#verify}

Check the boundary after extraction:

- the controller should still receive input objects
- the service should not mention `RequestInterface`, route attributes, or response types

You can also exercise the service over HTTP on `http://127.0.0.1:9501/api`:

```bash
curl -X PUT http://127.0.0.1:9501/api/tasks/2/complete
```

## Next {#next}

Next, [04 — Stored data](04-data.md#04-data) keeps the same service but makes task data survive restarts.
