# Add caching {#add-caching}

Use this guide when you want to speed up repeated reads without turning Redis details or full-page caching rules into the center of the application too early.

## Choose the right cache layer {#cache-layer}

Start with the layer that matches the real thing you want to reuse.

| Layer | Best when | Keep in mind |
|---|---|---|
| [Cache](../cache/README.md) | one application read path should reuse JSON-serializable results behind a service boundary | strongest default for read-through caching; source of truth still lives in persistence or other application logic |
| [HTTP Cache](../http-cache/README.md) | one public or request-parameter-driven endpoint should reuse the whole response body | correctness is mostly a key-design problem; hidden user/session variance makes this layer unsafe |
| [Redis](../redis/README.md) | command-level Redis control, named clients, or connection-bound behavior is the real problem | use it when raw commands or client topology matter; it is not the first abstraction for normal app caching |

Common shape: **Cache** for service-level reads, **HTTP Cache** for whole responses, **Redis** when raw commands or client topology matter.

## Recommended build order {#build-order}

1. Start only after the read path is already correct.
2. Choose the smallest correct cache layer.
3. Cache one small read path first.
4. Use `HTTP Cache` only for public request-shaped output.
5. Split Redis clients only when workloads really differ.
6. Add invalidation before you widen TTL or scope.

## Minimal wiring {#minimal-wiring}

Use short code only to show the seams between the cache layers.

Application-level names like `TaskRepository`, `TaskService`, and `TaskController` below are illustrative. The Switon framework symbols and config surfaces are the stable part.
If your app applies a global router prefix such as `/api`, keep the controller mappings below prefix-free and confirm the real public URL with `bash bin/console router:prefix`.

### Shared Redis base {#wiring-redis}

A small shared Redis setup is enough for the first cache path:

```yaml
# switon.yml (project root)
Switon\Redis\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: ${REDIS_URI:-redis://127.0.0.1:6379/0}
  cache:
    uri: ${CACHE_REDIS_URI:-redis://127.0.0.1:6379/1?pool_size=16}

Psr\SimpleCache\CacheInterface:
  prefix: 'tasks:list:'
  redisCache: '#cache'

Switon\Caching\Command\CacheCommand:
  prefix: 'tasks:list:'
  redisCache: '#cache'
```

Two config conventions matter here:

- `${REDIS_URI:-redis://127.0.0.1:6379/0}` means "read the `REDIS_URI` environment variable, or use this default value when it is empty or missing"
- `'#cache'` is a named-service reference, shorthand for the Redis client `Switon\Redis\ClientInterface#cache`

This is enough to keep application cache and cache CLI on the same namespace.
`prefix` belongs to the service id directly above it.
If you do not need a separate Redis database or pool policy yet, one default Redis client is also fine.

### Application data cache path {#wiring-cache}

Use [Cache](../cache/README.md) first when one service read path should reuse JSON-serializable results:

```php
namespace App\Service;

use App\Entity\Task;
use App\Repository\TaskRepository;
use Psr\SimpleCache\CacheInterface;
use Switon\Core\Attribute\Autowired;

class TaskListService
{
    #[Autowired] protected CacheInterface $cache;
    #[Autowired] protected TaskRepository $taskRepository;

    public function listOpenTasks(): array
    {
        $miss = new \stdClass();
        $items = $this->cache->get('open', $miss);

        if ($items === $miss) {
            $items = array_map(
                static fn (Task $task): array => $task->toArray(),
                $this->taskRepository->all(['completed' => false]),
            );
            $this->cache->set('open', $items, 120);
        }

        return $items;
    }

    public function refreshOpenTasks(): void
    {
        $this->cache->delete('open');
    }
}
```

Keep the cached value on arrays, scalars, or other JSON-serializable shapes. Do not treat cache like an in-memory PHP object store for ORM entities.

### Public response cache path {#wiring-http-cache}

Use [HTTP Cache](../http-cache/README.md) when the whole response is safe to reuse for a request-shaped key:

```php
namespace App\Controller;

use App\Service\TaskServiceInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Http\RequestInterface;
use Switon\HttpCache\Attribute\PageCache;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('')]
class TaskController
{
    #[Autowired] protected TaskServiceInterface $taskService;

    #[GetMapping('/tasks')]
    #[PageCache(ttl: 120, key: ['status', 'page'])]
    public function listAction(RequestInterface $request): array
    {
        $status = (string) $request->get('status', 'open');
        $page = (int) $request->get('page', 1);

        return [
            'status' => $status,
            'page' => $page,
            'items' => $this->taskService->listVisibleTasks($status, $page),
        ];
    }
}
```

If the app adds a global prefix such as `/api`, the public route becomes `/api/tasks` even though the controller mapping stays `'/tasks'`.

Keep the key aligned with every request input that changes the response.

### Raw Redis only when the command surface is the hard part {#wiring-raw-redis}

Drop to [Redis](../redis/README.md) when exact Redis commands or named-client boundaries matter more than cache semantics:

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Redis\ClientInterface;

class CacheOpsService
{
    #[Autowired] protected ClientInterface $redis;

    public function rememberTaskCount(int $userId, int $count): void
    {
        $this->redis->set("task-count:{$userId}", (string) $count, 120);
    }
}
```

For ordinary application caching, [Cache](../cache/README.md) is usually the better first boundary.

## What to avoid first {#pitfalls}

- **Caching before the read path is stable** — first make the response or query boundary correct
- **Using one long TTL everywhere** — short-lived cache is easier to reason about and safer to invalidate
- **Caching hidden user/session-specific output with `HTTP Cache`** — page cache keys must reflect every output-changing input
- **Using raw Redis where `Cache` already fits** — command-level control is not the same as a good application cache boundary
- **Caching ORM entities as if object identity will survive round-trips** — normalize entities to arrays or other JSON-serializable values first
- **Treating Redis as the source of truth** — cache should reuse data, not own business state
- **Expanding surface before invalidation is clear** — add refresh/delete/expiry rules before you widen coverage

## Go deeper {#go-deeper}

- **To connect this after your first API and stored-data paths:** [Build an API](build-an-api.md#build-an-api) · [Persist data](persist-data.md#persist-data)
- **To learn the same move through the tutorial example:** [Faster list](../tutorial/faster-list.md#faster-list)
- **To add authentication where hidden current-user state now matters more than public cache reuse:** [Add authentication](add-authentication.md#add-authentication) · [Session](../session/README.md) · [JWT](../jwt/README.md)
- **To stay on application data caching:** [Cache](../cache/README.md)
- **To cache full HTTP responses:** [HTTP Cache](../http-cache/README.md) · [Response caching](../http/response.md#caching)
- **To tune Redis clients, pools, or raw commands:** [Redis](../redis/README.md)
- **To react to writes or observe cache flows:** [Event](../event/README.md)
