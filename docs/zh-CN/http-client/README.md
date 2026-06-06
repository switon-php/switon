---
sidebar_label: HTTP Client 包
description: switon/http-client：HttpClientInterface、JSON 快捷方法、RequestOptions 与连接池。
---

# HTTP Client 包 {#http-client-package}

`switon/http-client` 为 Switon 应用提供**统一的 HTTP 客户端契约**：JSON 优先的快捷方法、分类异常和响应读取辅助。

**关键特性：**

- **统一契约：** 在服务中注入 `Switon\HttpClient\HttpClientInterface`；
- **JSON 优先：** `get()`、`post()`、`put()`、`patch()`、`delete()` 是 JSON API 的常用路径；
- **原始请求：** 需要时用 `request()`、`head()` 走底层传输；
- **响应辅助：** `Response` 提供 `json()`、`text()`、`status()`、`header()` 与原始响应头；
- **单次请求选项：** `RequestOptions` 覆盖超时、代理、CA 和 TLS 校验；
- **多部分上传：** `LocalFile`、`MemoryFile`；
- **按源连接池：** 同一 origin 重复调用时复用引擎。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`ext-curl`

```bash
composer require switon/http-client
```

---

## 快速开始 {#quick-start}

注入 `HttpClientInterface`，然后直接调用 JSON 快捷方法。

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\HttpClient\HttpClientInterface;

class UserService
{
    #[Autowired] protected HttpClientInterface $httpClient;

    public function featuredUsers(): array
    {
        return $this->httpClient
            ->get('https://api.example.com/users/featured')
            ->json();
    }
}
```

只需覆盖超时时用 `RequestOptions::of(3)`。客户端按 origin 维护小池，`pool_size` 控制默认容量。

---

## 核心概念 {#core-concepts}

### JSON 优先请求 {#json-first-requests}

快捷方法就是给 JSON API 准备的：

- 数组 body 会编码成 JSON；
- 自动附加 `Accept: application/json`、`X-Requested-With: XMLHttpRequest` 等头；
- 响应用 `json()` 解析为数组；
- `request()` 保留原始 method/body 路径，**非 2xx 仍抛异常**；
- `head()` 走和 `request()` 相同的传输路径，**不做** JSON 解析；
- 字符串 body 只有在看起来像对象或数组 JSON 时才会自动按 JSON 处理。

非 JSON 端点或需完全控制 method/body 时用 `request()`。

### 响应 {#responses}

`Response` 提供稳定访问器：

- `json()` — 解码 JSON；
- `text()` — UTF-8 文本；
- `status()` — 状态码；
- `header()` — 单个头；
- `rawHeaders()` — 重定向后的最终头块。

需要 Cookie 时用 `getCookies()` 读 `Set-Cookie`。

### 请求选项 {#request-options}

`RequestOptions` 是**单次请求**的覆盖层：`timeout`、`connect_timeout`、`proxy`、`cafile`、`verify_peer`。

`RequestOptions::of(3)` 等价于 `['timeout' => 3]`。未设 `connect_timeout` 时用客户端默认值。

### 连接池 {#pooling}

按请求 **origin**（scheme、host、port）建池；`pool_size` 是每个 origin 的默认容量；借出超时跟随本次 `timeout`。

### 上传 {#uploads}

- `LocalFile` — 发送时从磁盘读取；
- `MemoryFile` — 内存字节，无需先写文件。

### 生命周期钩子 {#lifecycle-hooks}

单次请求会发出：`HttpClientStart`、`HttpClientRequesting`、`HttpClientRequested`、`HttpClientSuccess` / `HttpClientError`、`HttpClientComplete`。可用于追踪、指标或轻量可观测性。

---

## 配置 {#configuration}

在 `switon.yml` 设置应用边界的默认请求行为：

```yaml
# switon.yml
Switon\HttpClient\HttpClientInterface:
  timeout: 3
  connect_timeout: 1
  verify_peer: true
  pool_size: 4
```

---

## 选项发现 {#option-discovery}

```bash
bash bin/console config:options 'Switon\HttpClient\HttpClientInterface'
```

---

## 使用注意 {#keep-in-mind}

- 非 JSON 或需原始 method 路径时再用 `request()`；
- 确认响应为 JSON 后再调 `json()`；
- 磁盘上传用 `LocalFile`，内存载荷用 `MemoryFile`；
- 基础 URL 后追加查询参数可用数组 URL 形式；
- 过慢、TLS 过严或走代理时先查 `RequestOptions`。

---

## 下一步 {#next}

- **短答与边角：** [FAQ](faq.md#http-client-faq)
- **取舍与边界：** [设计说明](design.md#design-rationale)
