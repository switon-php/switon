---
sidebar_label: HTTP Cache FAQ
description: HTTP Cache：PageCacheFilter、键、ETag/304、POST 与 Redis 配置。
---

# HTTP Cache FAQ {#http-cache-faq}

**接线、键与未命中相关的短答。**

> **前置：** [HTTP Cache 包](README.md#http-cache-package)

## 入门 {#getting-started}

### 要手动注册过滤器吗？ {#manual-filter-registration}

一般不用。正常安装会通过监听器元数据发现 `PageCacheFilter`，但仍须在 **`RequestHandler.filters`** 中**启用**该过滤器。

### 怎么给单个动作开页面缓存？ {#enable-caching}

在动作上加 `#[PageCache]` 并设置 TTL（默认仅 **3 秒**）。

### 适合缓存什么？ {#what-to-cache}

**单个动作的最终响应体**，且重复请求应复用相同输出时。

## 配置 {#configuration}

### 页面缓存选项在哪配？ {#configure-filter}

`switon.yml` 里的 `Switon\HttpCache\Filter\PageCacheFilter`。

### 怎么用单独的 Redis？ {#configure-redis}

定义命名的 `Switon\Redis\ClientInterface`，`redisCache` 指向它。未改 `prefix` 时默认为 `cache:{app_id}:page_cache:`。

### 怎么看 Redis 相关选项？ {#discover-options}

`bash bin/console config:options 'Switon\Redis\ClientInterface'`

## 缓存键 {#cache-keys}

### 默认怎么生成键？ {#default-key-generation}

默认是**控制器动作**本身。

### 怎么收窄键？ {#custom-key-params}

在 `#[PageCache]` 里传 `key`。

### 为什么拿到错误页面变体？ {#wrong-variant}

通常是 `key` 里少了一个会改变输出的值。

## 排错 {#troubleshooting}

### 缓存没用上，先查什么？ {#cache-not-used}

`#[PageCache]`、请求方法、`ttl`、是否为 **`200`**、Redis 键是否存在。  
**POST 也可命中**，键相同即可。

### 为什么看不到 `304`？ {#missing-304}

客户端须发 `If-None-Match`，且缓存 `ETag` 仍匹配。缓存存 gzip；客户端接受 gzip 时可返回压缩体。

### JSON 为什么像没命中 HTML 缓存？ {#json-variant}

预期行为：JSON 请求走**独立变体**。
