---
sidebar_label: 请求
description: 经统一请求边界读取路由值、查询、正文、头、文件与属性。
---

# 请求 {#request}

经同一请求边界读取路由值、查询、正文、头、上传文件与属性。

**关键特性：**

- **合并输入** — `get()`、`has()`、`all()`、`only()`、`except()` 作用于同一合并后的请求映射
- **按源读取** — 需要区分来源时使用 `route()`、`query()`、`post()`
- **文件访问** — `files()`、`file()`、`hasFile()` 处理上传
- **请求元数据** — `header()`、`headers()`、`server()`、`ip()`、`url()`、`path()` 暴露 HTTP 上下文
- **类型化输入衔接** — `#[RequestBody]`、`#[RequestQuery]`、`#[RequestData]` 将请求数据转为类型化输入对象

## 快速开始 {#quick-start}

当动作需要原始请求数据或元数据时，注入 `Switon\Http\RequestInterface`。

```php
namespace App\Controller;

use Switon\Http\Controller;
use Switon\Http\RequestInterface;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/search')]
class SearchController extends Controller
{
    #[GetMapping('')]
    public function searchAction(RequestInterface $request): array
    {
        return [
            'keyword' => $request->get('keyword'),
            'page' => $request->query('page', 1),
            'ip' => $request->ip(),
        ];
    }
}
```

需要区分来源时用按源读取；解析或校验逻辑开始扩散时，改用类型化输入类。

## 核心概念 {#core-concepts}

### 合并输入 {#merged-input}

`Switon\Http\RequestInterface::all()` 合并路由值、已解析的 POST/正文与查询数据；同名冲突时路由值优先。

`Switon\Http\RequestInterface::get()` 与 `has()` 读取同一合并映射。

### 按源读取 {#source-specific-reads}

仅需单一来源时使用：

- `route()` — 路由值
- `query()` — 查询字符串
- `post()` — 已解析的正文

### 文件与元数据 {#files-and-metadata}

`Switon\Http\RequestInterface` 也提供上传与元数据。

- `files()`、`file()` — 上传
- `header()`、`headers()` — 请求头
- `server()` — 服务器变量
- `ip()`、`url()`、`path()`、`verb()`、`isAjax()` — 常用请求事实

### 类型化输入衔接 {#typed-hand-off}

当动作需要稳定的输入形状时，绑定类型化输入类，而不是在动作里直接读原始请求。

- `#[RequestBody]` — 已解析的正文
- `#[RequestQuery]` — 查询
- `#[RequestData]` — 合并后的请求

## 注意 {#keep-in-mind}

- `Switon\Http\RequestInterface::get()` 将 `null` 视为缺失输入。
- `Switon\Http\RequestInterface::all()` 是路由之后的合并存储。
- JSON 请求体会先解析进 POST 数据，再参与合并输入读取。

## 下一步 {#next}

- **绑定路由与控制器动作：** [控制器](controller.md)
- **构造响应：** [响应](response.md)
- **类型化输入绑定详解：** [类型化输入绑定](typed-input-binding.md)
