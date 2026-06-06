---
sidebar_label: Router 包
description: switon/router：RequestMapping 发现、前缀与 router:list/resolve。
---

# Router 包 {#router-package}

`switon/router` 负责发现控制器映射、构建路由表，并将请求与字面量和动态路径匹配。这个包**只**负责路径到处理器的解析；请求绑定与响应形态由 `switon/http` 负责。

**关键特性：**

- **属性发现：** `#[RequestMapping]` 和动词映射把路由意图留在控制器上。
- **可预测组合：** 控制器前缀与方法路径按稳定规则拼接。
- **混合匹配：** 字面量路由用精确查找；占位符路由用编译后的模式。
- **前缀处理：** `setPrefix()` 接受固定前缀或条件模式，例如 `?/v\d+`。
- **诊断：** `router:list`、`router:resolve` 和 `router:prefix` 可查看已注册内容及匹配结果；`router:resolve` 可输出 JSON。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`switon/core`、`switon/event`、`psr/event-dispatcher`

```bash
composer require switon/router
```

---

## 快速开始 {#quick-start}

```php
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/user')]
class UserController
{
    #[GetMapping('{id}')]
    public function showAction(): void
    {
    }
}
```

该控制器注册 **`GET /user/{id}`**。

---

## 核心概念 {#core-concepts}

### 发现 {#discovery}

- `Switon\Routing\MappingDiscoveryInterface` 解析控制器映射。
- 发现**要求**类级 `#[RequestMapping]`。
- 没有映射属性的 public 方法会被忽略。
- 若类上有多个类级 `#[RequestMapping]`，发现**只使用第一个**。

### 映射 {#mappings}

- 类级 `#[RequestMapping]` 标记控制器参与发现并设置其前缀。
- 方法级动词映射（`#[GetMapping]`、`#[PostMapping]` 等）设定 HTTP 方法。
- 方法级 `#[RequestMapping]` 会把 HTTP 方法设为 `*`（任意动词）。

### 视图映射 {#view-mappings}

`ViewGetMapping`、`ViewPostMapping`、`ViewPatchMapping` 与 `ViewPutMapping` 遵循相同路径规则，并附加面向视图的元数据。对非 `GET` 的视图映射，注册器会在同一路径上额外配一条 `GET` 路由。

### 注册 {#registration}

`Switon\Routing\RouteRegistrarInterface` 会把已发现路由注册到 `Switon\Routing\RouterInterface`。`register()` 扫描配置的文件；`registerClass()` 直接添加单个控制器。

### 路径规则 {#path-rules}

- 类级 `#[RequestMapping]` 没有 path 时，会从控制器名推导出 `kebab-case` 前缀；`#[RequestMapping('')]` 保持空前缀。
- 方法 path 为 `null` 时，会从方法名推导 `kebab-case`（去掉 `Action` 后缀）；`''` 只保留控制器前缀。
- 方法 path 以 `/` 开头时为**绝对路径**，会忽略控制器前缀。
- 数组前缀和数组 path 会注册**所有组合**。

### 匹配 {#matching}

- 字面量路由按精确的 `verb + path` 匹配；`*` 是动词回退，会在特定动词之后检查。
- 动态路由使用 `{id}`、`{id:int}`、`{id:[0-9]+}` 等占位符。未指定占位符时，默认匹配一段非 `/` 内容。
- 内置片段：`{controller}`、`{action}`、`{id}`、`:uuid`、`:slug`、`:alpha`、`:alphanum`、`:any`、`:var`。
- **后注册**的动态路由会优先检查。默认大小写敏感。

### 前缀 {#prefixes}

`setPrefix()` 在匹配前剥离 URL 前缀，使存储的路由模式保持**无前缀**。

- 固定前缀：`setPrefix('/api/v1')` 匹配该精确前置路径。
- 条件前缀：`setPrefix('?/v\d+')` 匹配前置 token 加模式；例如 `getPrefix('/tenant/v2/users')` 返回 `/tenant/v2`。
- 当 URI 不满足条件前缀时，`getPrefix($uri)` 会抛出 `NotFoundRouteException`。

---

## 配置 {#configuration}

先从一个前缀和一张路由表开始。只有当同一应用需要在可变前导段（租户、版本等）下响应时，才使用条件前缀。

默认控制器扫描路径：`@app/Controller/*Controller.php` 和 `@app/Areas/*/Controller/*Controller.php`。启用 `cache` 时，`RouteRegistrar` 可以在 `@runtime/cache/routes.json` 读写 JSON 缓存；删除该文件可刷新缓存路由。

```bash
bash bin/console router:list
bash bin/console router:resolve /api/users/1 GET
bash bin/console router:prefix
```

`router:resolve` 在 JSON 模式下返回 `matched`、`path`、`verb`、`results` 与 `error`；省略动词时会尝试常见 HTTP 方法。

---

## 使用注意 {#keep-in-mind}

- 发现**必须**有类级 `#[RequestMapping]`；类上**仅**第一个生效。
- 方法上 `#[GetMapping]` 且无 path 时，路径为方法名的 `kebab-case`（去掉 `Action`）。
- Router **只**匹配路径并提取字符串；类型转换属于请求绑定层。
- `RouterCollector` 通过名为 `router` 的 inspector 收集器暴露路由数据；`RouteNotFound` 与 `RouteParamsResolved` 事件有助于诊断。

---

## 下一步 {#next}

- **请求绑定或响应形态：** [HTTP 控制器指南](../http/controller.md#controller)
- **常见问题：** [FAQ](faq.md#router-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
