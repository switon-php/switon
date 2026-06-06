---
sidebar_label: 更快的列表
description: 为读侧增加一层缓存，使重复的任务列表请求保持廉价。
---

# 旁路 — 更快的列表 {#faster-list}

## 目标 {#goal}

为重复的任务列表请求增加一条缓存支撑的读路径。

## 代码 {#shape}

下文中的 `TaskQueryService` 等应用命名仅为示例。下列片段展示读缓存部分，请合并进现有控制器与服务，而非整类替换。

`Psr\SimpleCache\CacheInterface` 与自定义 miss 标记是稳定模式；`Task` 继承 `Switon\Orm\Entity` 因而有适合 JSON 的 `toArray()`。`60` 秒 TTL 仅为短演示，不是框架默认值。

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

将列表读路径接到 `TaskQueryService`：

```php
namespace App\Controller;

use App\Input\TaskFilterInput;
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
    public function listAction(TaskFilterInput $query): array
    {
        return $this->taskQueryService->list($query->completed);
    }
}
```

读密集列表走 `TaskQueryService`；写入仍走 `TaskServiceInterface`。在写入后显式失效：

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

习惯：只缓存读密集结果；用 miss 标记区分「未命中」与缓存 `null`；缓存前把 ORM 实体规范化；写入决定何时清除读缓存。更广缓存模式见 [Cache](../cache/README.md) 与 [Redis](../redis/README.md)。

## 步骤 {#steps}

1. 从一条已存在的列表或详情读路径开始。
2. 将该读路径接到以查询为中心的服务（如 `TaskQueryService`）。
3. 选定稳定缓存键（如 `task:list:v1`）。
4. 先用短 TTL 缓存，验证新鲜度规则。
5. 在创建、完成或清理等改变列表的写入后清除相关键。

## 验证 {#verify}

对同一条读路径连续请求两次；第二次应命中缓存，直到写入使缓存失效。

## 下一步 {#next}

缓存路径清晰后，回到 [教程索引](README.md#main-path)，或尝试 [定时清理](timed-cleanup.md#timed-cleanup)。
