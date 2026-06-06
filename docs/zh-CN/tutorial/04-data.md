---
sidebar_label: 持久化
description: 用真实持久化替换假数据，使任务在进程重启后仍可查询。
---

# 04 — 存储数据 {#04-data}

用真实持久化替换假数据或内存结果，使任务在进程重启后仍可查询。

## 目标 {#goal}

用**基于仓储的持久化**替换假结果。

**主要包：** [ORM](../orm/README.md)

## 代码 {#shape}

下文中的 `Task`、`TaskRepository`、`TaskService` 等应用命名仅为示例。Switon ORM 映射属性、基类与仓储优先形态是稳定部分；此处主要变在服务实现。

```php
namespace App\Entity;

use Switon\Orm\Attribute\Column;
use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\Table;
use Switon\Orm\Entity;

#[Table('tasks')]
final class Task extends Entity
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
```

```php
namespace App\Repository;

use App\Entity\Task;
use Switon\Orm\Repository;

final class TaskRepository extends Repository
{
}
```

```php
namespace App\Service;

use App\Entity\Task;
use App\Repository\TaskRepository;
use Switon\Core\Attribute\Autowired;

final class TaskService implements TaskServiceInterface
{
    #[Autowired] protected TaskRepository $taskRepository;

    public function list(?bool $completed = null): array
    {
        $filters = $completed === null ? [] : ['completed' => $completed];

        return array_map(
            fn (Task $task): array => $this->taskPayload($task),
            $this->taskRepository->all($filters),
        );
    }

    public function create(string $title, ?string $note = null): array
    {
        $task = $this->taskRepository->create([
            'title' => $title,
            'note' => $note,
            'completed' => false,
        ]);

        return $this->taskPayload($task);
    }

    public function complete(int $id): array
    {
        $task = $this->taskRepository->get($id);
        $task->completed = true;

        $task = $this->taskRepository->update($task->toArray());

        return $this->taskPayload($task);
    }

    private function taskPayload(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'note' => $task->note,
            'completed' => $task->completed,
        ];
    }
}
```

实体描述已存储数据，仓储拥有持久化操作，服务仍拥有应用决策。服务仍返回数组载荷是刻意的：HTTP/CLI 保持与第 `03` 章相同的载荷形态。仅在列名与属性名不一致时需要 `#[Column]`。

在默认 `App\Repository\TaskRepository` → `App\Entity\Task` 命名下，仓储可自动推断实体。**仅**在内存中修改实体不会持久化，必须调用仓储写入方法。

## 步骤 {#steps}

1. 先引入一个小实体与对应仓储。
2. 调试仓储前先建表。ORM 映射到**已存在**的表；`create()` 失败时先查库路径、表是否存在以及实体/仓储命名。
3. 先把读者已熟悉的 `create()` 路径接到仓储。
4. `create()` 跑通后，再把读取与 `complete()` 等路径切到仓储。行必须存在时用 `get()`；允许空结果时用 `find()`。本教程中 `listAction()` 仍经 `TaskServiceInterface::list()`，`complete()` 应更新已存储任务。

一般一个 SQLite 文件即可。选真实路径，并在建表与运行时使用同一文件。最小 `switon.yml` 可参考：

```yaml
# switon.yml
Switon\Db\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: sqlite:///build/tutorial/tasks.db
```

验证应用中通常通过 `config/client.php` 提供同类接线。不确定当前使用的库时：

```bash
bash bin/console config:options 'Switon\Db\ClientInterface'
```

务必在**该**数据库文件中创建 `tasks` 表后再调用仓储：

```sql
CREATE TABLE tasks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  note TEXT NULL,
  completed INTEGER NOT NULL DEFAULT 0
);
```

## 验证 {#verify}

创建一条任务后再列一次。数据应来自存储而非内存假数据。若写入看似成功但 `GET /api/tasks` 仍为空，多半是应用与 SQL 客户端指向了不同的 SQLite 文件。

```bash
curl -X POST http://127.0.0.1:9501/api/tasks \
  -H 'Content-Type: application/json' \
  -d '{"title":"Persisted task"}'

curl http://127.0.0.1:9501/api/tasks
curl -X PUT http://127.0.0.1:9501/api/tasks/1/complete
```

## 下一步 {#next}

持久化就绪后，[05 — CLI 复用](05-cli.md#05-cli) 从控制台调用同一服务。
