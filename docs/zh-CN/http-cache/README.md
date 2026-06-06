---
sidebar_label: HTTP Cache 包
description: switon/http-cache：#[PageCache]、Redis 页面缓存、ETag 与 304 再验证。
---

# HTTP Cache 包 {#http-cache-package}

`switon/http-cache` 为**单个控制器动作**提供基于 Redis 的**页面缓存**：键可由请求派生，并支持 `ETag` 再验证。

**关键特性：**

- **动作级控制：** `#[PageCache]` 标记可缓存动作；
- **请求派生键：** `key` 可含请求输入或固定片段；
- **HTTP 再验证：** 支持 `ETag` 与 `304 Not Modified`；
- **Redis 存储：** 页面体经 `Switon\Redis\ClientInterface` 共享；
- **方法边界：** 查找覆盖 `GET`、`POST`、`HEAD`；仅 **`200`** 响应会写入；
- **监听器注册：** 经 `extra.switon.listeners` 发现 `PageCacheFilter`（监听器元数据），**不是** `RequestHandler.filters`。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`switon/http`、`switon/redis`、`switon/event`

```bash
composer require switon/http-cache
```

---

## 快速开始 {#quick-start}

在「对放入 `key` 的值而言输出稳定」的动作上使用 `#[PageCache]`。

```php
namespace App\Controller;

use Switon\Http\RequestInterface;
use Switon\HttpCache\Attribute\PageCache;
use Switon\Routing\Attribute\GetMapping;

class ProductController
{
    #[GetMapping('/products')]
    #[PageCache(ttl: 600, key: ['category', 'page'])]
    public function listAction(RequestInterface $request): array
    {
        $category = $request->get('category', 'all');
        $page = (int)$request->get('page', 1);

        return [
            'category' => $category,
            'page' => $page,
            'items' => $this->loadProducts($category, $page),
        ];
    }
}
```

首次符合条件的 **`200`** 响应写入 Redis。之后相同解析键可复用缓存体；`If-None-Match` 匹配时可返回 **`304 Not Modified`**。

过滤器已为 `Accept: application/json` 保留**独立变体**。  
查找也覆盖 **`POST`**，因此只应缓存对 POST 仍合法可复用的响应。

---

## 核心概念 {#core-concepts}

### 页面缓存 {#page-cache}

`PageCache` 为动作声明 TTL。默认 TTL 仅 **3 秒**，真实页面请**显式**设置。

```php
#[PageCache(ttl: 600)]
```

### 缓存键 {#cache-key}

默认键为**控制器动作本身**。传入 `key` 时，在动作键之上叠加请求值或固定片段。

真实页面请显式写 `key`，并包含**所有**会改变输出的值。

```php
#[PageCache(ttl: 600, key: ['category', 'page'])]
#[PageCache(ttl: 600, key: ['lang' => 'en', 'category'])]
```

### 再验证 {#revalidation}

缓存项带 `ETag`。客户端发 `If-None-Match` 且匹配时，可 **`304`** 而不重复传 body。若渲染结果 `ETag` 不变，写入缓存步骤也可短路为 `304`。

### Redis 存储 {#redis-storage}

过滤器在 Redis 存 `content`、`content-type`、`etag`、`ttl`，body 以 **gzip** 保存；使用应用绑定的 `Switon\Redis\ClientInterface`。

### 方法范围 {#method-scope}

查找：`GET`、`POST`、`HEAD`。新写入仅对成功的 **`200`**。

---

## 配置 {#configuration}

```yaml
# switon.yml
Switon\HttpCache\Filter\PageCacheFilter:
  prefix: 'cache:myapp:page_cache:'
  redisCache: '#pageCache'
```

需要自定义前缀或独立 Redis 客户端时在 `switon.yml` 配置。

默认前缀：`cache:{app_id}:page_cache:`。未设 `redisCache` 时用默认 `Switon\Redis\ClientInterface` 绑定。

---

## 选项发现 {#option-discovery}

```bash
bash bin/console config:options 'Switon\HttpCache\Filter\PageCacheFilter'
```

若页面缓存使用命名 Redis 客户端，也可查看 `Switon\Redis\ClientInterface`。

---

## 使用注意 {#keep-in-mind}

- 凡能改变输出的值都要进缓存键；
- 领域数据变更时**不会**自动失效；
- 命中缓存会以 `StopFlow` 中止动作流；
- 页面缓存针对**最终响应体**，不要替代应用层可复用数据缓存。

---

## 下一步 {#next}

- **未命中与键设计：** [FAQ](faq.md#http-cache-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
