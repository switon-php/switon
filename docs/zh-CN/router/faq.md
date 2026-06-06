---
sidebar_label: Router FAQ
description: Router：空前缀 vs 省略、setPrefix 与 router:list/resolve。
---

# Router FAQ {#router-faq}

**接线与路由问题相关的短答。**

> **阅读前提：** [Router 包](README.md)

## 入门 {#getting-started}

### 控制器为什么没被发现？ {#controller-not-discovered}

查扫描规则，并确认类上有**类级** `#[RequestMapping]`。

### `#[RequestMapping]` 与 `#[RequestMapping('')]`？ {#request-mapping-empty}

省略/`null` → 从控制器名推导 kebab-case 前缀；`''` → **空前缀**。

### `#[GetMapping]` 与 `#[GetMapping('')]`？ {#get-mapping-empty}

省略 → 从方法名推导路径；`''` → **只用**控制器前缀。

### Router 会把 `{id}` 转成 `int`？ {#router-cast}

**不会。** 只匹配路径并提取字符串；类型化在 HTTP/Binding 层。

## 路径 {#paths}

### 为什么 `/api/v1/users` 不匹配？ {#prefix-mismatch}

在 Router 上 `setPrefix('/api/v1')`，注册模式应为 **`/users`**，不是 `/api/v1/users`。条件前缀不匹配时 `getPrefix` 可能抛 `NotFoundRouteException`。

### 数组前缀/路径？ {#array-paths}

注册**所有组合**。

### 绝对路径为何忽略控制器前缀？ {#absolute-path}

以 **`/`** 开头的方法路径视为绝对路径。

## 命令 {#commands}

### 如何查看全部路由？ {#inspect-routes}

`bash bin/console router:list`（可选过滤器匹配 `VERB path`，支持子串/通配符）。

### 如何解析单条路径？ {#resolve-path}

`bash bin/console router:resolve /api/users/1 GET`；省略动词时会尝试多种方法，可能返回多个匹配。

### 如何看当前前缀？ {#check-prefix}

`bash bin/console router:prefix`
