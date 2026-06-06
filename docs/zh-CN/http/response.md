---
sidebar_label: 响应
description: 通过动作返回值或 ResponseInterface 构造 HTTP 响应。
---

# 响应 {#response}

通过动作的正常返回值，或在需要显式控制时使用 `Switon\Http\ResponseInterface` 构造 HTTP 响应。

**关键特性：**

- **数组返回路径** — 返回数组走常见 JSON 响应流程
- **显式响应控制** — 用 `ResponseInterface` 设置状态、头、Cookie、重定向、文件与正文
- **渲染器** — `XmlRendererInterface`、`CsvRendererInterface`、`AttachmentRendererInterface` 用于非 JSON 输出
- **流式输出** — 响应非单次缓冲正文时可分块写入
- **集中收尾** — 响应塑形仍经 HTTP 生命周期

## 快速开始 {#quick-start}

返回数组即走常见 JSON 路径。

```php
namespace App\Controller;

use Switon\Http\Controller;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/users')]
class UserController extends Controller
{
    #[GetMapping('')]
    public function indexAction(): array
    {
        return ['users' => ['Alice', 'Bob']];
    }
}
```

需要状态码、头、重定向、下载或流式输出时，使用 `Switon\Http\ResponseInterface`。

## 核心概念 {#core-concepts}

### 常规响应路径 {#normal-response-path}

返回数组是 JSON 响应的常见路径。

- 框架规范化返回值
- 动作专注于业务数据
- 响应为普通 JSON 时使用此路径

### 动作返回值 {#action-return-values}

| 返回类型 | 响应 | 典型用途 |
|---|---|---|
| `array` | 常规 JSON 载荷 | 多数动作 |
| `null` | 空成功载荷 | 无数据可返回 |
| `int` | 业务码载荷 | 简单状态简写 |
| `string` | 字符串载荷 | 直接文本或其他基于字符串的约定 |
| `ResponseInterface` | 已处理完毕 | 显式响应控制 |
| `Throwable` | 异常流 | 交给 HTTP 异常处理 |

### 显式响应控制 {#explicit-response-control}

动作需直接写入响应元数据时，注入 `Switon\Http\ResponseInterface`。

- `setStatus()` — 状态码
- `setHeader()`、`setCookie()` — 头与 Cookie
- `redirect()` — 准备重定向并终止当前流程
- `setFile()`、`write()` — 文件与分块输出

### 缓存 {#caching}

仅当整段响应可按请求形状的键安全复用时，才使用响应缓存。

### 渲染器 {#renderers}

输出格式本身是需求时，使用渲染器契约。

- `Switon\Http\Response\XmlRendererInterface`
- `Switon\Http\Response\CsvRendererInterface`
- `Switon\Http\Response\AttachmentRendererInterface`

## 注意 {#keep-in-mind}

- `Switon\Http\Response::redirect()` 会刻意终止当前流程。
- `Switon\Http\ResponseInterface::json()` 用于显式 JSON 输出。
- 目标格式不再是 JSON 时才使用渲染器。

## 下一步 {#next}

- **绑定控制器动作：** [控制器](controller.md)
- **读取请求输入：** [请求](request.md)
- **选择运行时设置：** [服务器与部署](server.md)
