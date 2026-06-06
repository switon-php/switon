---
sidebar_label: 控制器
description: 路由属性、动作参数、类型化输入与控制器级扩展点。
---

# 控制器 {#controller}

用路由属性、动作参数、类型化输入与控制器作用域内的扩展点定义 HTTP 端点。

**关键特性：**

- **属性路由** — `#[RequestMapping]` 设定控制器前缀，`#[GetMapping]`、`#[PostMapping]` 等将动词映射到动作
- **动作参数** — 路由值、请求输入与 DI 服务可共用一个动作签名
- **类型化输入** — `#[RequestBody]`、`#[RequestQuery]`、`#[RequestData]` 将请求绑定到带校验的类型化输入类
- **动作级扩展点** — 视图、鉴权与拦截器贴近各自影响的动作
- **集中异常流** — 控制器错误经 HTTP 异常路径收敛，而非在动作里本地 `try/catch`

## 快速开始 {#quick-start}

在 `app/Controller/` 或 `app/Areas/*/Controller/` 下放一个控制器：类上加 `#[RequestMapping]`，动作上加动词属性。

```php
namespace App\Controller;

use Switon\Http\Controller;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/user')]
class UserController extends Controller
{
    #[GetMapping('')]
    public function indexAction(int $page = 1): array
    {
        return ['page' => $page];
    }

    #[GetMapping('{id}')]
    public function showAction(int $id): array
    {
        return ['id' => $id];
    }

    #[PostMapping('')]
    public function createAction(string $name, string $email): array
    {
        return ['name' => $name, 'email' => $email];
    }
}
```

路由在启动时发现。若路由未出现，可执行 `bash bin/console router:list`。

## 核心概念 {#core-concepts}

### 路由 {#routing}

控制器路由来自类与动作上的属性。

- `#[RequestMapping]` 设定控制器前缀
- `#[GetMapping]`、`#[PostMapping]`、`#[PutMapping]`、`#[PatchMapping]`、`#[DeleteMapping]` 映射 HTTP 动词
- `#[ViewGetMapping]`、`#[ViewPostMapping]`、`#[ViewMapping]` 面向需要渲染视图的路由

### 动作参数 {#action-parameters}

动作参数按名称或类型解析。

- 路由值按名称绑定
- 标量动作参数从请求输入读取
- 服务参数由容器解析

优先使用精简的动作签名。仅当动作需要头、上传文件等原始请求数据时，再使用 `Switon\Http\RequestInterface`。

### 类型化输入 {#typed-input}

当单个动作需要稳定的请求形状时，使用类型化输入类。

- `#[Switon\Http\Attribute\RequestBody]` 读取已解析的请求体
- `#[Switon\Http\Attribute\RequestQuery]` 读取查询参数
- `#[Switon\Http\Attribute\RequestData]` 读取合并后的请求数据

嵌套的类型化属性无需重复该属性；绑定过程中会执行校验。

### 鉴权 {#authorization}

鉴权属性守卫动作边界；拦截器只包裹其挂载的动作。

### 视图渲染 {#view-rendering}

当控制器动作应输出 HTML 而非普通 JSON 时，使用视图层。

- `#[ViewGetMapping]` 对普通 GET 渲染 HTML；客户端偏好 JSON 时仍会调用动作
- `#[ViewMapping]` 在 GET 上始终渲染 HTML，并仍调用动作以准备数据

客户端偏好 JSON 时，`#[ViewGetMapping]` 可跳过动作方法执行；`#[ViewMapping]` 不会跳过。

### 异常 {#exceptions}

控制器动作无需本地渲染异常。

- 框架异常经 HTTP 异常分发器收敛
- 控制器代码可专注于正常返回值
- 仅在默认 HTTP 响应策略不足时使用自定义处理器

## 注意 {#keep-in-mind}

- `#[GetMapping('')]` 仅保留类前缀。
- `#[GetMapping]` 用动作名作为路由后缀。
- 常见 JSON 路径请返回数组。
- 动作参数不够用时再引入 `Switon\Http\RequestInterface`。

## 下一步 {#next}

- **读取输入与请求元数据：** [请求](request.md)
- **构造响应：** [响应](response.md)
- **类型化输入绑定详解：** [类型化输入绑定](typed-input-binding.md)
- **常见问题：** [FAQ](faq.md)
