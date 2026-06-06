---
sidebar_label: HTTP 包
description: switon/http：控制器、Request/Response 边界、生命周期事件与运行时适配。
---

# HTTP 包 {#http-package}

`switon/http` 为 Switon 应用提供**统一的基于控制器的 HTTP 边界**：请求输入、响应塑形、生命周期事件与运行时适配。

**关键特性：**

- **控制器动作：** 用 `#[RequestMapping]` 与 `switon/router` 的 HTTP 动词属性把路由映射到方法
- **请求边界：** 通过 `Switon\Http\RequestInterface` 读取路由、查询、正文、头、文件与属性
- **响应边界：** 通过 `Switon\Http\ResponseInterface` 设置状态、头、Cookie、重定向、下载与流式输出
- **类型化输入绑定：** 用 `#[RequestBody]`、`#[RequestQuery]`、`#[RequestData]` 将正文、查询或合并请求数据绑定到类型化输入类
- **生命周期钩子：** 在请求/响应事件上挂横切逻辑，而不是散落在各个 action 里
- **过滤器层：** 请求级过滤器承载请求 ID、语言环境、映射检查等横切行为
- **运行时适配：** 同一套 HTTP 代码可在 FPM、PHP 内置服务器或 Swoole 上运行
- **命令行检视：** `filter:list` 列出已发现的过滤器，并标出在 `RequestHandler` 中启用的项

## 安装 {#installation}

**环境要求：** PHP 8.3+、`ext-dom`；Swoole 可选，仅在需要 Swoole 运行时时安装。

```bash
composer require switon/http
```

默认将 `Switon\Core\InputInterface` 绑定到 `Switon\Http\RequestInterface`。

---

## 快速开始 {#quick-start}

定义一个控制器 action，返回数组，由框架规范化为 JSON。

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

    #[GetMapping('{id}')]
    public function showAction(int $id): array
    {
        return ['id' => $id];
    }

    #[GetMapping('search')]
    public function searchAction(string $keyword, int $page = 1): array
    {
        return ['keyword' => $keyword, 'page' => $page];
    }
}
```

足以跑通第一条路由。标量 action 参数按名称绑定，故 `int $id`、`string $keyword`、`int $page` 来自路由或请求输入。需要原始输入时用 `Switon\Http\RequestInterface`；仅在状态、头、重定向、文件或流式输出需要显式控制时用 `Switon\Http\ResponseInterface`。

若应用配置了全局路由前缀（如 `/api`），对外 URL 为 `/api/users`、`/api/users/{id}`。

---

## 核心概念 {#core-concepts}

### 控制器动作 {#controller-actions}

控制器类承载路由声明，action 方法承载 HTTP 入口。

- `#[RequestMapping]` 设置控制器前缀
- `#[GetMapping]`、`#[PostMapping]` 等将动词映射到 action
- action 参数接收路由值与注入的服务

### 请求 {#request}

`Switon\Http\RequestInterface` 是读取路由值、查询、解析后的正文、头、文件与请求属性的边界。

### 类型化输入 {#typed-input}

当某个 action 需要稳定、可校验的请求形状时，使用类型化输入类。

```php
namespace App\Controller;

use Switon\Http\Attribute\RequestBody;
use Switon\Http\Controller;
use Switon\Routing\Attribute\PostMapping;
use Switon\Validating\Attribute\Length;

#[RequestBody]
class CreateUserInput
{
    #[Length(1, 64)]
    public string $name;
}

class UserController extends Controller
{
    #[PostMapping('/users')]
    public function createAction(CreateUserInput $input): array
    {
        return ['name' => $input->name];
    }
}
```

- `#[RequestBody]` 读取解析后的正文
- `#[RequestQuery]` 读取查询参数
- `#[RequestData]` 读取合并后的请求数据

### 响应 {#response}

`Switon\Http\ResponseInterface` 是写入状态、头、Cookie、重定向、文件与正文的边界。
常规 JSON 直接返回数组；需要显式控制时用 `ResponseInterface`。

### 响应终结 {#finalization}

响应终结发生在路由、校验、调用与渲染全部完成之后，是 HTTP 流程的收尾阶段。

### 生命周期事件 {#lifecycle-events}

请求流由扩展点驱动。

- 请求阶段事件包括 `adjust`、`begin`、`authenticate`、`route`、`authorize`、`validate`、`ready`、`invoke`、`render`、`end`。翻译文档时这些名称仍作**事件标识符**，保持英文。
- 响应阶段事件包括 `adjust`、`stringify`、`headers`、`body`，以及在服务端写出输出前的资源发送。同上，保持英文。
- 监听器是请求级横切（请求 ID、语言环境、日志等）的主要落点
- 认证与校验事件是扩展点；具体行为来自 authz、validation、throttle 等监听器

### 运行时适配 {#runtime-adapters}

`Switon\Http\ServerOptions` 选择监听地址、端口、适配器类型及适配器专属配置。
默认 `type: auto`。

---

## 配置 {#configuration}

在 `switon.yml` 中配置 HTTP 主契约。

```yaml
# switon.yml
Switon\Http\ServerOptions:
  type: auto
  host: 0.0.0.0
  port: 9501
```

### 选项发现 {#option-discovery}

直接查看当前选项面：

```bash
bash bin/console config:options 'Switon\Http\ServerOptions'
```

---

## 使用注意 {#keep-in-mind}

- `Switon\Http\RequestInterface::all()` 合并路由、POST 与查询输入。
- `Switon\Http\RequestInterface::get()` 将 `null` 视为缺失输入。
- `Switon\Http\Response::redirect()` 会**有意**终止当前流程。
- 请求级横切放在监听器里，不要塞进控制器属性。

## 下一步 {#next}

- **绑定路由、action 与类型化输入：** [Controller](controller.md#controller)
- **读取输入与请求元数据：** [Request](request.md#request)
- **塑形响应、下载与流式输出：** [Response](response.md#response)
- **选择服务端适配器与运行时设置：** [Server](server.md#server-deployment)
- **深入了解类型化正文、查询与合并输入：** [类型化输入绑定](typed-input-binding.md#typed-input-binding)
- **在响应上设置与检查 Cookie：** [Cookie](cookies.md#cookies)
- **常见问题：** [FAQ](faq.md#http-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
