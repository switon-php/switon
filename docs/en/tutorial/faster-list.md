---
sidebar_label: Fast List
description: Add one read-side cache so repeated task list requests stay cheap.
---

# Side path — Faster list {#faster-list}

## Goal {#goal}

Add one cache-backed read path for repeated task list requests.

## Code {#shape}

`TaskQueryService` is an illustrative name.
The snippets below show the added read-cache parts. Merge them into your existing controller and service instead of replacing the whole class.

```php
namespace App\Service;

use App\Entity\Task;
use App\Repository\TaskRepository;
use Psr\SimpleCache\CacheInterface;
use Switon\Core\Attribute\Autowired;

final class TaskQueryService
{
    #[Autowired] protected CacheInterface $cache;
    #[Autowired] protected TaskRepository $taskRepository;

    public function list(?bool $completed = null): array
    {
        $miss = new \stdClass();
        $key = $completed === null
            ? 'task:list:v1'
            : 'task:list:' . ($completed ? 'completed' : 'active') . ':v1';
        $cached = $this->cache->get($key, $miss);

        if ($cached !== $miss) {
            return $cached;
        }

        $filters = $completed === null ? [] : ['completed' => $completed];
        $tasks = array_map(
            static fn (Task $task): array => $task->toArray(),
            $this->taskRepository->all($filters),
        );
        $result = [
            'tasks' => $tasks,
        ];

        $this->cache->set($key, $result, 60);

        return $result;
    }

    public function forgetListCache(): void
    {
        $this->cache->deleteMultiple([
            'task:list:v1',
            'task:list:completed:v1',
            'task:list:active:v1',
        ]);
    }
}
```

`$miss` distinguishes cache miss from cached `null`.
`60` is a demo TTL, not a framework default.

Now rewire the read path so the cached result is actually used:

```php
namespace App\Controller;

use App\Http\Request\TaskFilterQuery;
use App\Service\TaskQueryService;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Controller;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[Authorize(Authorize::ANONYMOUS)]
#[RequestMapping('/tasks')]
final class TaskController extends Controller
{
    #[Autowired] protected TaskQueryService $taskQueryService;

    #[GetMapping('')]
    public function listAction(TaskFilterQuery $query): array
    {
        return $this->taskQueryService->list($query->completed);
    }
}
```

Read-heavy list traffic goes through `TaskQueryService`.
Writes still go through `TaskServiceInterface`.

```php
use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Service\TaskQueryService;
use Switon\Core\Attribute\Autowired;

final class TaskService implements TaskServiceInterface
{
    #[Autowired] protected TaskRepository $taskRepository;
    #[Autowired] protected TaskQueryService $taskQueryService;

    public function create(string $title, ?string $note = null): array
    {
        $task = $this->taskRepository->create([
            'title' => $title,
            'note' => $note,
            'completed' => false,
        ]);

        $this->taskQueryService->forgetListCache();

        return $task->toArray();
    }

    public function complete(int $id): array
    {
        $task = $this->taskRepository->get($id);
        $task->completed = true;

        $task = $this->taskRepository->update($task);

        $this->taskQueryService->forgetListCache();

        return $task->toArray();
    }
}
```

## Steps {#steps}

1. Start from one existing list or detail read path.
2. Rewire that read path to a query-focused service such as `TaskQueryService`.
3. Pick one stable cache key such as `task:list:v1`.
4. Cache the result for a short TTL first.
5. Clear that key after create, complete, or cleanup operations change the list.

## Verify {#verify}

Call the same read path twice.
The second read should come from cache until a write invalidates the cached result.

## Next {#next}

When the cache story makes sense, return to the [tutorial index](README.md#main-path), or try [Timed cleanup](timed-cleanup.md#timed-cleanup) for time-based work.
