---
sidebar_label: Cache FAQ
description: Redis 缓存：PSR-16、JSON 值、miss 标记、前缀、批量与 CLI。
---

# Cache FAQ {#cache-faq}

安装、配置与日常运维的短答。

> **前置：** [Cache 包](README.md#cache-package)

## 入门 {#getting-started}

### 怎么在业务里加缓存？ {#add-caching}

注入 `Psr\SimpleCache\CacheInterface`，按 cache-aside 写。见 [快速开始](README.md#quick-start)。

### 为什么 `get()` 拿到的总是数组而不是对象？ {#json-values}

值以 JSON 存盘，读出为**数组 / 标量 / null**。需要对象时在业务代码里组装。

### 能缓存 `null` 吗？ {#cache-null}

能。此时必须用**与 miss 不同的哨兵**，避免把「真的缓存了 null」当成未命中。

### 键里能用什么字符？ {#key-characters}

`user:123`、`config:v2:app` 这类 Redis 常用风格即可。若要跨后端，自行收紧字符集。

### TTL 怎么设？ {#size-and-ttl}

`set` 的第二、三参数：**整型秒数**、`DateInterval`，或 **`null`** 表示不过期。

## 配置 {#configuration}

### 在哪儿配？ {#configure}

在 `switon.yml` 里配 `Psr\SimpleCache\CacheInterface`。隔离主要靠 **`prefix`**（默认 `cache:`）。需要时可用 `redisCache: '#name'` 让某个实例用**具名**的 `Switon\Redis\ClientInterface`。

### 为什么空前缀会让 `clear()` 抛错？ {#prefix-required}

空前缀会像「清空半片共用 Redis」，风险极大；因此包选择**直接拒绝**。`cache:list` 同理。

### 能多套缓存并行吗？ {#multiple-instances}

可以。用 `Switon\Di\Factory` 配多条不同 `prefix`；必要时再给某一槽加 `redisCache: '#name'` 指向单独的 Redis 客户端。

## 运维 {#operations}

### 怎么减少往返？ {#batch}

已有多个 key 时，用 **`getMultiple` / `setMultiple` / `deleteMultiple`**。

### 要先 `has()` 再 `get()` 吗？ {#has-before-get}

一般不需要。单次 `get` + miss 标记更省一趟 Redis。

### 终端里怎么看、怎么清？ {#cli}

用 `cache:list`、`cache:clear`；前缀须与运行时配置一致。

## 排错 {#troubleshooting}

### Redis 挂了或 JSON 坏了怎么办？ {#error-handling}

把缓存当作**加分项**。在读写外围包 `\Throwable`，失败就回落权威数据源。

- JSON 语义错误：**解析异常**，不是静默 miss。
- 连接中断：单独处理 Redis 错误。

空前缀：`clear()` 会抛 **`CachePrefixRequiredException`**，`cache:list` 也会拦截。

### 明明应该有数据却只有 `null` {#cache-miss}

若业务上 **`null` 合法**，请务必用哨兵区分 miss。

### 怎么测用到 Cache 的代码？ {#testing}

单测可直接 mock `CacheInterface`；集成测用独立 Redis DB 或测试容器。
