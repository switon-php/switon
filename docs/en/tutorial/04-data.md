---
sidebar_label: Persistence
description: Replace fake results with real persistence so tasks survive restarts.
---

# 04 — Stored data {#04-data}

## Goal {#goal}

Replace fake results with repository-backed persistence.

**Primary package:** [ORM](../../packages/orm/docs/README.md)

## Code {#shape}

`Task`, `TaskRepository`, and `TaskService` are illustrative names.
Create `app/Entity/Task.php` and `app/Repository/TaskRepository.php`, then replace `app/Service/TaskService.php` with the service below.

```php
namespace App\Entity;

use Switon\Orm\Attribute\Id;
use Switon\Orm\Attribute\Table;
use Switon\Orm\Entity;

#[Table('tasks')]
final class Task extends Entity
{
    #[Id]
    public ?int $id = null;

    public string $title;

    public ?string $note = null;

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
        $task = new Task();
        $task->title = $title;
        $task->note = $note;
        $task->completed = false;

        $task = $this->taskRepository->create($task);

        return $this->taskPayload($task);
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

        return $this->taskPayload($task) + ['found' => true];
    }

    private function taskPayload(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'note' => $task->note,
            'completed' => (bool) $task->completed,
        ];
    }
}
```

Entity models data, repository persists data, service keeps business rules.
Use `#[Column]` only when the database column name differs from the property name.

## Steps {#steps}

1. Add one small entity and one repository.
2. Create the database table before debugging repository code.
3. Move the familiar `create()` path to the repository first.
4. Then switch read paths to the repository too.

For a normal app, one SQLite file is enough. Pick one real file path and keep using that same file for both schema creation and runtime reads.
A minimal `switon.yml` can look like this:

```yaml
# switon.yml
Switon\Db\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: sqlite:///build/tutorial/tasks.db
```

In the tutorial proof app, the same DB wiring is provided through `config/client.php`.

Inspect the active DB options when you are not sure what your app is using:

```bash
bash bin/console config:options 'Switon\Db\ClientInterface'
```

Create the `tasks` table in that exact database file before you call the repository:

```sql
CREATE TABLE tasks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  note TEXT NULL,
  completed INTEGER NOT NULL DEFAULT 0
);
```

Prefer `get()` when the row must exist, and `find()` when empty results are acceptable.
In this tutorial:

- `listAction()` should now read from `TaskServiceInterface::list()`
- `complete()` should now update the stored task instead of returning a fake array

Switon ORM writes are explicit: changing an entity in memory does not persist until a repository write method runs.

## Verify {#verify}

Create one task, then list tasks again.
The data should now come from storage instead of a fake in-memory result.
If the write looks successful but `GET /api/tasks` still stays empty, the app and your SQL client are probably pointing at different SQLite files.

Use the proof-app default paths below:

```bash
curl -X POST http://127.0.0.1:9501/api/tasks \
  -H 'Content-Type: application/json' \
  -d '{"title":"Persisted task"}'

curl http://127.0.0.1:9501/api/tasks
curl -X PUT http://127.0.0.1:9501/api/tasks/1/complete
```

## Next {#next}

With persistence in place, [05 — CLI reuse](05-cli.md#05-cli) runs the same service from the console.

