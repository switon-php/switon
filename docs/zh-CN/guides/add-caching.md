---
description: 在不过早把 Redis 细节或整页缓存规则变成应用中心的前提下，加速重复读取。
---

# 添加缓存 {#add-caching}

当你希望加速重复读取，且不想过早把 Redis 细节或整页缓存规则变成应用中心时，使用本指南。

## 选对缓存层 {#cache-layer}

从与你希望复用的真实对象匹配的层起步。

| 层 | 最适合 | 注意 |
|---|---|---|
| [Cache](../cache/README.md) | 一条应用读路径应在 service 边界后复用 JSON 可序列化结果 | 读穿缓存的最强默认；真相来源仍在持久化或其他应用逻辑 |
| [HTTP Cache](../http-cache/README.md) | 一个公开或由请求参数驱动的端点应复用完整响应体 | 正确性主要是键设计问题；隐藏的用户/会话差异使该层不安全 |
| [Redis](../redis/README.md) | 命令级 Redis 控制、命名客户端或连接绑定行为才是真正问题 | 当原始命令或客户端拓扑重要时使用；对常规应用缓存不是首选抽象 |

一种常见的应用形态是：

- **Cache** 用于 service 后的普通读穿数据复用
- **HTTP Cache** 用于输出仅随显式请求输入变化的公开列表/详情端点
- **Redis** 用于共享支撑、命名客户端，或更高缓存层已不够时的更低层命令工作流

## 推荐构建顺序 {#build-order}

1. 仅在读路径已经正确、可信之后再开始（不要用缓存掩盖控制器逻辑或不稳定响应）。
2. 选择最小且正确的层：应用读复用 [Cache](../cache/README.md)，整响应复用 [HTTP Cache](../http-cache/README.md)，原始 Redis 拓扑或命令才是重点时再用 [Redis](../redis/README.md)。
3. 先缓存一条小读路径（短 TTL、清晰键）。
4. 仅对**公开、可由请求塑形**的输出使用 [HTTP Cache](../http-cache/README.md)；隐藏会话/用户态通常不适用。
5. 仅在工作负载确实不同时拆分 [Redis](../redis/README.md) 命名客户端。
6. 在放宽 TTL 或扩大范围之前先处理失效/刷新策略。

## 最小接线 {#minimal-wiring}

仅用短代码展示各缓存层之间的接缝。

下文中的 `TaskRepository`、`TaskService`、`TaskController` 等应用级名称仅为示意。Switon 框架符号与配置表面才是稳定部分。
若应用使用 `/api` 等全局路由前缀，控制器映射保持无前缀，并用 `bash bin/console router:prefix` 确认真实公开 URL。

### 共享 Redis 基础 {#wiring-redis}

对第一条缓存路径，一份小的共享 Redis 配置即可：

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

此处两条配置约定很重要：

- `${REDIS_URI:-redis://127.0.0.1:6379/0}` 表示「读取 `REDIS_URI` 环境变量；为空或缺失时使用该默认值」
- `'#cache'` 是命名服务引用，即 Redis 客户端 `Switon\Redis\ClientInterface#cache` 的简写

这足以让应用缓存与缓存 CLI 落在同一命名空间。
此处的 `prefix` 属于其正上方的服务 id：在 `Psr\SimpleCache\CacheInterface` 下控制运行时缓存键；在 `Switon\Caching\Command\CacheCommand` 下让 CLI 检查落在同一命名空间。
若尚不需要独立 Redis 数据库或池策略，一个默认 Redis 客户端也可以。

### 应用数据缓存路径 {#wiring-cache}

当一条 service 读路径应复用 JSON 可序列化结果时，优先使用 [Cache](../cache/README.md)：

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

这样缓存语义留在 service 边界之后，持久化仍是真相来源。
缓存值保持在数组、标量或其他 JSON 可序列化形态。不要把缓存当作 ORM 实体的内存 PHP 对象存储。

### 公开响应缓存路径 {#wiring-http-cache}

当完整响应对由请求塑形的键可安全复用时，使用 [HTTP Cache](../http-cache/README.md)：

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

若应用添加 `/api` 等全局前缀，即使控制器映射仍是 `'/tasks'`，公开路由也会变成 `/api/tasks`。

让键与每个会改变响应的请求输入对齐。
若不确定，从安全的默认全请求键起步，之后再收窄。

### 仅当命令表面才是难点时才用原始 Redis {#wiring-raw-redis}

当精确 Redis 命令或命名客户端边界比缓存语义更重要时，下沉到 [Redis](../redis/README.md)：

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

仅当你需要控制 Redis 命令本身时留在此层。
对常规应用缓存，[Cache](../cache/README.md) 通常是更好的首选边界。

## 初期应避免什么 {#pitfalls}

有意让第一条缓存路径保持狭窄。

过早避免：

- **在读路径尚不稳定时就缓存** — 先让响应或查询边界正确
- **到处使用一个长 TTL** — 短生命周期缓存更易推理，也更安全地失效
- **用 `HTTP Cache` 缓存隐藏的用户/会话相关输出** — 页面缓存键必须反映每个会改变输出的输入
- **在 `Cache` 已适用时使用原始 Redis** — 命令级控制不等于良好的应用缓存边界
- **把 ORM 实体当作对象身份能跨越往返的缓存** — 先把实体规范为数组或其他 JSON 可序列化值
- **把 Redis 当作真相来源** — 缓存应复用数据，而非拥有业务状态
- **在失效尚不清晰时扩大覆盖面** — 在加宽覆盖之前，先增加刷新/删除/过期规则

完成第一条缓存路径后，初学者应能感到：

> 我知道自己在缓存什么、为何该层合适，以及陈旧数据如何离开系统。

## 继续深入 {#go-deeper}

- **在第一条 API 与已存数据路径之后衔接：** [构建 API](build-an-api.md#build-an-api) · [持久化数据](persist-data.md#persist-data)
- **通过教程示例学习同样动作：** [更快的列表](../tutorial/faster-list.md#faster-list)
- **在隐藏当前用户状态比公开缓存复用更重要时添加认证：** [添加认证](add-authentication.md) · [Session](../session/README.md) · [JWT](../jwt/README.md)
- **继续应用数据缓存：** [Cache](../cache/README.md)
- **缓存完整 HTTP 响应：** [HTTP Cache](../http-cache/README.md) · [响应缓存](../http/response.md#caching)
- **调优 Redis 客户端、池或原始命令：** [Redis](../redis/README.md)
- **对写入做出反应或观察缓存流：** [Event](../event/README.md)
