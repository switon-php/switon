---
description: 在一条可用 HTTP 路径上添加请求级钩子、仅包裹 action 的逻辑或仅针对失败的响应策略，而不过早把 HTTP 内部细节变成应用中心。
---

# 添加横切 HTTP 行为 {#add-cross-cutting-http-behavior}

当一条可用的 HTTP 路径现在需要请求级钩子、仅作用于 action 的包装，或仅针对失败的响应策略，且你不希望过早把 HTTP 内部细节变成应用中心时，使用本指南。

## 选择正确的扩展点 {#extension-point}

从行为的真实作用域起步，而不是从机制名称起步。

| 需求 | 使用 | 注意 |
|---|---|---|
| 每个请求或某一请求生命周期阶段 | `Switon\Http\RequestHandlerInterface` → `filters` 下的生命周期监听器 | 此处的 `filters` 是生命周期监听器的配置键，**不是**链式 `$next()` 管道；用于请求 ID、CORS、访问日志、慢日志与认证身份加载 |
| 仅一个 action 或一组 action | 控制器拦截器或仅作用于 action 的响应 attribute | 最适合 `#[RequireJson]`、`#[CacheControl]`、`#[ETag]` 或其他应留在受影响 action 上的前后包装 |
| 仅逃逸的异常应变成一种 HTTP 策略 | `Switon\Http\ExceptionDispatcherInterface` → `handlers` 下的异常处理器 | 适合维护模式、领域特定错误输出或一条共享失败规则；这是失败策略，不是正常请求编排 |

一种常见应用形态是：

- **生命周期监听器** — 请求 ID、CORS、访问日志与当前调用方加载
- **仅 action 的拦截器或 attribute** — 某条路由的守卫、包装、缓存头或校验器
- **异常处理器** — 异常已逃逸正常 action 流之后的类型化失败映射

## 推荐构建顺序 {#build-order}

### 1. 在一条请求路径已经正确之后再开始 {#build-stable-path}

不要用 HTTP 扩展点掩盖不清晰的路由、绑定、校验或 service 边界问题。
先证明一条路由已能到达一个 action 并返回正确结果。

### 2. 先按作用域选，再按机制选 {#build-scope}

先问一个问题：谁应感受到该行为？

- **所有请求** → 生命周期监听器
- **仅部分 action** → 拦截器或 action attribute
- **仅失败映射** → 异常处理器

这一个决策通常能消除大部分关于请求级 filter 与拦截器的困惑。

### 3. 自定义请求级代码之前优先用内置监听器 {#build-builtins}

HTTP 包已自带请求级 filter，如 `Switon\Http\Filter\RequestIdFilter`、`Switon\Http\Filter\CorsFilter`、`Switon\Http\Filter\AccessLogFilter` 与 `Switon\Http\Filter\SlowlogFilter`。
当它们已匹配工作时，先通过配置启用。

### 4. 把局部行为留在 action 附近 {#build-local-behavior}

若一项关切只属于一个端点或一族控制器 action，就放在 action 上。
这样 `#[RequireJson]`、`#[CacheControl]`、`#[ETag]` 及类似局部行为会留在响应契约实际变化之处、可见。

### 5. 把仅失败策略移入异常处理器 {#build-failure-policy}

若行为仅在异常逃逸之后才开始，不要把它硬塞进生命周期监听器或 action 拦截器。
类型化异常处理器能在不重塑正常请求流的情况下，集中失败格式化与响应策略。

### 6. 证明一条接缝后停下 {#build-stop}

不要把第一次 HTTP 扩展变成完整平台设计。
在一项关切放置正确之后，再判断下一个真实问题是认证、缓存还是更广的 API 契约工作。

## 最小接线 {#minimal-wiring}

下面只用短片段展示请求级钩子、action 局部行为与失败策略之间的接缝。

下文应用级名称如 `App\Listener\SessionIdentityListener`、`RequireJson`、`MaintenanceHandler` 仅为示例；Switon 框架符号与配置根才是稳定部分。
若应用有 `/api` 等全局路由前缀，下面控制器映射保持无前缀，并用 `bash bin/console router:prefix` 确认真实公开 URL。

### 请求级生命周期监听器 {#wiring-listeners}

当行为应作用于请求生命周期而非单个控制器 action 时，在 `Switon\Http\RequestHandlerInterface` 下注册监听器：

```yaml
# switon.yml (project root)
Switon\Http\RequestHandlerInterface:
  filters:
    requestId: Switon\Http\Filter\RequestIdFilter
    cors:
      class: Switon\Http\Filter\CorsFilter
      origin: https://example.com
    auth: App\Listener\SessionIdentityListener
```

此处 `filters` 仍是生命周期监听器的配置键名。

适合放在该接缝上的行为包括：

- 确保存在请求 ID
- 处理 CORS 与预检响应
- 写访问日志或慢日志
- 在控制器运行前把当前调用方载入 `IdentityInterface`

当身份加载本身成为主要任务时，见 [添加认证](add-authentication.md)。

### 仅 action 的拦截器与响应 attribute {#wiring-action-scope}

当行为应仅在该路由变化处可见时，把 action 局部行为留在 action 上。

自定义拦截器可在 action 体运行前拒绝或包装一族 action：

```php
use Attribute;
use ReflectionMethod;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Attribute\Interceptor;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;

#[Attribute(Attribute::TARGET_METHOD)]
class RequireJson extends Interceptor
{
    #[Autowired] protected RequestInterface $request;
    #[Autowired] protected ResponseInterface $response;

    public function preHandle(ReflectionMethod $method): bool
    {
        if (!$this->request->wantsJson()) {
            $this->response->json(['code' => 406, 'msg' => 'JSON only'], 406);
            return false;
        }

        return true;
    }
}
```

`RequireJson` 继承 `Switon\Core\Attribute\Interceptor`。当拦截器需要 HTTP 上下文时，在拦截器自身上用 `#[Autowired]` 注入 `Switon\Http\RequestInterface` 与 `Switon\Http\ResponseInterface`。

当响应行为仅局部于一个端点时，使用内置 action attribute：

```php
namespace App\Controller;

use App\Service\TaskServiceInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Attribute\CacheControl;
use Switon\Http\Attribute\ETag;
use Switon\Http\ResponseInterface;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('')]
class TaskController
{
    #[Autowired] protected TaskServiceInterface $taskService;

    #[CacheControl(maxAge: 120, public: true)]
    #[ETag('updated_at')]
    #[GetMapping('/tasks')]
    public function listAction(ResponseInterface $response): void
    {
        $response->json([
            'items' => $this->taskService->listVisibleTasks(),
            'updated_at' => $this->taskService->latestUpdateAt(),
        ]);
    }
}
```

适合放在该接缝上的行为包括：

- 单个 action 上的仅 JSON 或基于头的守卫
- 单个端点的缓存校验器或缓存头
- 围绕特定控制器 action 的局部前后包装

若应用加了 `/api` 等全局前缀，公开路由为 `/api/tasks`，尽管控制器仍映射 `'/tasks'`。

### 仅针对失败的异常处理器 {#wiring-exception-handlers}

当关切属于已逃逸的异常而非正常请求流时，在 `Switon\Http\ExceptionDispatcherInterface` 下注册异常处理器：

```yaml
# switon.yml (project root)
Switon\Http\ExceptionDispatcherInterface:
  handlers:
    App\Exception\MaintenanceException: App\ExceptionHandler\MaintenanceHandler
```

该块位于根 `switon.yml` 中服务 id `Switon\Http\ExceptionDispatcherInterface` 下。`handlers` 映射为「异常类」→「处理器类」。

```php
namespace App\ExceptionHandler;

use Switon\Core\Attribute\Autowired;
use Switon\Http\ExceptionHandlerInterface;
use Switon\Http\ResponseInterface;

class MaintenanceHandler implements ExceptionHandlerInterface
{
    #[Autowired] protected ResponseInterface $response;

    public function handle(\Throwable $exception): bool
    {
        if (!$exception instanceof MaintenanceException) {
            return false;
        }

        $this->response->json(['code' => 503, 'msg' => 'Service under maintenance'], 503);
        return true;
    }
}
```

`Switon\Http\ExceptionHandlerInterface` 是该接缝的小型 HTTP 失败钩子。它只有一个方法 `handle(\Throwable $exception): bool`：当本处理器已决定 HTTP 结果时返回 `true`，否则返回 `false` 让分发器继续回退处理或其他处理器。

当真实需求是一条共享的失败结果，而不是另一条正常请求钩子时，使用此接缝。

## 首条路径上宜避免什么 {#pitfalls}

有意让第一条 HTTP 扩展路径保持窄。

下列内容不宜过早：

- **把仅 action 的行为放进全局监听器** — 若仅一条路由需要，就保持局部
- **在许多控制器里解析头、Cookie 或认证状态** — 请求级关切应有一条生命周期接缝
- **用拦截器处理属于异常处理器的失败策略** — 已逃逸的异常已有专用 HTTP 映射接缝
- **把隐藏的当前用户输出当作安全的页面缓存输入** — 在更广的响应缓存之前，session 或令牌差异必须显式
- **把第一次扩展长成完整认证、追踪或缓存平台** — 先清楚证明一条放置边界

完成第一条扩展路径后，初学者应能感到：

> 我知道哪条 HTTP 接缝拥有该行为，以及它为何属于那里。

## 继续深入 {#go-deeper}

- **在扩展之前构建首条 JSON 路由与控制器路径：** [构建 API](build-an-api.md#build-an-api)
- **在扩展之前构建首条控制器到模板页面路径：** [构建 HTML 页面](build-html-pages.md#build-html-pages)
- **选择类型化输入绑定、控制器与正常请求处理细节：** [HTTP](../http/README.md) · [Controller](../http/controller.md)
- **追踪生命周期顺序、监听器注册与异常流：** [理解请求生命周期](../concepts/request-lifecycle.md)
- **保持认证身份加载在请求级，并下一步保护端点：** [添加认证](add-authentication.md) · [Session](../session/README.md) · [JWT](../jwt/README.md) · [Authz](../authz/README.md)
- **添加路由级滥用保护并有意拥有 `429` 响应表面：** [用速率限制保护端点](protect-endpoints-with-rate-limits.md)
- **把响应局部缓存校验器与输出行为留在单个 action：** [Response](../http/response.md)
- **当重复读取成为真实问题时选择第一条安全缓存层：** [添加缓存](add-caching.md#add-caching) · [HTTP Cache](../http-cache/README.md)
- **在路由、认证与响应行为稳定后发布契约：** [添加 API 文档](add-api-docs.md)
