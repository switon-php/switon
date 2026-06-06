---
sidebar_label: Cache 包
description: Switon cache（switon/cache）：Redis、PSR-16、JSON 值、前缀清理与批量 API。
---

# Cache 包 {#cache-package}

`switon/cache` 给 Switon 应用提供 **Redis 后端**、遵循 **PSR-16** 的 `Psr\SimpleCache\CacheInterface`：**可读键名**、**JSON 存储**、`clear()` **只清当前前缀**，并支持批量与缓存事件。

**关键特性：**

- **标准契约：** 注入 `Psr\SimpleCache\CacheInterface`，照常调用 PSR-16。
- **Redis 友好键：** 如 `user:123`、`config:v2:app` 这一类命名可直接用。
- **可互操作的值：** 落盘为 JSON，便于人用、也便于与其它进程共享语义。
- **自动序列化：** 业务侧传原生数组与标量，无需手写 `json_encode`。
- **安全的 `clear()`：** **非空前缀**才可清理，避免误伤共用 Redis。
- **批量：** `getMultiple` / `setMultiple` / `deleteMultiple`。
- **事件：** `CacheHit`、`CacheMiss`、`CacheWritten`、`CacheMultipleWritten`、`CacheDeleted`、`CacheCleared`。

## 安装 {#installation}

**环境要求：** PHP 8.3+、`ext-redis`

```bash
composer require switon/cache
```

## 快速开始 {#quick-start}

注入 `Psr\SimpleCache\CacheInterface`，使用 **cache-aside**：

```php
namespace App\Service;

use stdClass;
use Psr\SimpleCache\CacheInterface;
use Switon\Core\Attribute\Autowired;

class ProductService
{
    #[Autowired] protected CacheInterface $cache;

    public function getFeatured(): array
    {
        $miss = new stdClass();
        $products = $this->cache->get('featured_products', $miss);

        if ($products === $miss) {
            $products = $this->loadFeaturedProducts();
            $this->cache->set('featured_products', $products, 1800);
        }

        return $products;
    }
}
```

- 若 **`null` 也可能是合法缓存值**，用**自定义 miss 标记**区分未命中。
- 若路径上永远不会缓存 `null`，也可以直接依赖 `get` 返回值判断。

## 核心概念 {#core-concepts}

### 键 {#keys}

使用稳定、可读、你**自己约定的**键字符串，并尽量让值保持 JSON 友好。

- Redis 风格的 `segment:segment` 命名没问题。
- 读取结果是数组、标量或 `null`。
- 需要领域对象时在业务代码里**自行还原**类型。

### 值 {#values}

写入前会做 JSON 编码。

- 方便人工排查，也方便非 PHP 消费者读同名 key。
- 调用方只打交道层数据结构。

### 未命中 {#misses}

若 **`null`** 既可以表示「没缓存」也可以表示「缓存了 null」，必须使用**哨兵对象**。

```php
$miss = new \stdClass();
$value = $cache->get($key, $miss);

if ($value === $miss) {
    // 回填
}
```

### TTL {#size-and-ttl}

`set()` 接受：**秒（int）**、**`DateInterval`**，或 **`null`**（不过期）。

### 前缀 {#prefixes}

- 默认前缀：**`cache:`**。
- `clear()` **只删除**与该前缀匹配的 key，从而降低误清其它业务数据的风险。

## 配置 {#configuration}

默认将 `Psr\SimpleCache\CacheInterface` 解析到 `Switon\Caching\SimpleCache`。

只有当你要 **换前缀 / 换 Redis 客户端 / 多实例命名空间** 时才必须在 `switon.yml` 里写额外配置。

### 基础配置 {#basic-configuration}

```yaml
# switon.yml
Psr\SimpleCache\CacheInterface:
  prefix: 'myapp:'
```

### 多实例 {#multiple-instances}

需要多套命名空间时，用 `Switon\Di\Factory` 按别名挂载不同 `prefix`：

```yaml
# switon.yml
Psr\SimpleCache\CacheInterface:
  class: Switon\Di\Factory
  default:
    class: Switon\Caching\SimpleCache
    prefix: 'cache:'
  users:
    class: Switon\Caching\SimpleCache
    prefix: 'users:'
```

注入时属性名与 **Factory 槽位名**一致，例如 `#[Autowired] protected CacheInterface $users;`。

若某个缓存实例要走**另一套 Redis 客户端**，把该槽的 `redisCache` 写成命名客户端引用，例如 `#pageCache`：

```yaml
# switon.yml
Psr\SimpleCache\CacheInterface:
  class: Switon\Di\Factory
  default:
    class: Switon\Caching\SimpleCache
    prefix: 'cache:'
  pageCache:
    class: Switon\Caching\SimpleCache
    prefix: 'page:'
    redisCache: '#pageCache'
```

```php
use Psr\SimpleCache\CacheInterface;
use Switon\Core\Attribute\Autowired;

class UserCacheWarmer
{
    #[Autowired] protected CacheInterface $users;
}
```

### 选项发现 {#option-discovery}

执行：

```bash
bash bin/console config:options 'Psr\SimpleCache\CacheInterface'
```

## 运维 {#operations}

### 批量操作 {#batch-operations}

本就有一批 key 要走时，优先用批量接口：

```php
$users = $cache->getMultiple(['user:1', 'user:2']);

$cache->setMultiple([
    'user:1' => ['id' => 1],
    'user:2' => ['id' => 2],
], 3600);

$cache->deleteMultiple(['user:1', 'user:2']);
```

### CLI {#cli-commands}

按前缀查看或清理：

```bash
bash bin/console cache:list
bash bin/console cache:list --showTtl
bash bin/console cache:clear
```

## 使用注意 {#keep-in-mind}

- **不要**在空前缀下调用 `clear()`；会破坏隔离。
- `cache:list` 同样拒绝空前缀。
- `get()` 得到的是 JSON 解码结果，**不是**任意自定义 PHP 对象。
- JSON 损坏会**抛解析错误**，不是「温和 miss」。
- `has()` 再 `get()` 多数是**多一次往返**。
- 把 `clear()` 理解为**前缀范围的清理**，不是「整库原子重置」。

## 下一步 {#next}

- **常见问题：** [FAQ](faq.md#cache-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
