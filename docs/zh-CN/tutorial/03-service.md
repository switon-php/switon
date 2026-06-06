---
sidebar_label: 服务层
description: 将任务业务逻辑移出控制器，放入可复用服务。
---

# 03 — 服务边界 {#03-service}

把任务业务逻辑移出控制器，使同一应用规则可从多个入口复用。

## 目标 {#goal}

把业务逻辑从控制器移到可复用的服务层。

**主要包：** [DI](../di/README.md#quick-start)

## 代码 {#shape}

下文 `TaskServiceInterface`、`TaskService`、`CreateTaskInput` 等应用名仅为示例。DI 模式与 `#[Autowired]` 是稳定部分。

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

use App\Input\CreateTaskInput;
use App\Input\TaskFilterInput;
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
    public function listAction(TaskFilterInput $query): array
    {
        return [
            'filter' => ['completed' => $query->completed],
            'tasks' => $this->taskService->list($query->completed),
        ];
    }

    #[PostMapping('')]
    public function createAction(CreateTaskInput $input): array
    {
        return [
            'task' => $this->taskService->create($input->title, $input->note),
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

本章尚不涉及持久化。控制器处理传输形态输入，服务处理业务规则；若服务签名仍出现 `RequestInterface`、路由属性或响应对象，划分尚不干净。

## 步骤 {#steps}

1. 先提取一个业务动作，通常从 `create()` 或 `complete()` 开始。
2. 依赖接口而非具体服务类。在默认约定 `App\Service\TaskServiceInterface` → `App\Service\TaskService` 下，DI 可自动映射；类名或命名空间不一致或有多实现时，请改回显式 DI 配置。
3. 让控制器只负责 HTTP：接收输入对象、用应用值调用服务、塑造响应；服务专注业务结果与返回值。

不需要 `BaseController`、`BaseService` 或辅助层也能把接缝看清楚，后续章节会继续复用这条边界。

## 验证 {#verify}

抽出服务后检查边界：

- 控制器仍接收输入对象；
- 服务不应再提 `RequestInterface`、路由属性或响应类型。

也可在 `http://127.0.0.1:9501/api` 上通过 HTTP 试一把：

```bash
curl -X PUT http://127.0.0.1:9501/api/tasks/2/complete
```

## 下一步 {#next}

接下来，[04 — 存储数据](04-data.md#04-data) 在同一服务边界上让任务数据在重启后仍存在。
