---
sidebar_label: 运行时与状态
description: Switon 单例安全、请求级可变状态与 Context 存在原因背后的核心规则。
---

# 理解运行时与状态 {#runtime-and-state}

理解 Switon 在单例安全、请求级可变状态以及为何需要 `Context` 的核心规则。

## 术语速览 {#terms}

若有术语陌生，边读边开着 [面向初学者与译者的术语表](glossary.md)。

- **singleton service** — 容器为进程生命周期解析的共享 service 实例
- **request-local state** — 只应存在于一次逻辑请求或一个协程作用域的数据
- **request attributes** — 在一次请求交接中挂在 HTTP request 对象上的请求绑定值
- **context** — 为某一 service 在某一 runtime 作用域持有可变状态的专用 `*Context` 对象
- **durable state** — 应超出当前请求、进程或协程存活的数据

## 在 Switon 里为何重要 {#matters-in-switon}

在短生命周期的 FPM 式流程里，service 上的可变属性可能看起来没什么问题，因为进程不够长，泄漏不明显。
在 Swoole 或 worker 式 CLI 这类长驻 runtime 中，同一个 service 实例可能会处理多次逻辑请求。

这时规则就变了：

- **共享 service 实例通常应保持无状态**
- **请求级可变状态应显式化**
- **持久业务数据应放在真实存储，而非 service 属性**

因此 `Context` 在 Switon 里是一等概念，不是事后补丁。

## 四类状态 {#state-kinds}

默认放置模型：

| 状态种类 | 通常归属 | 典型例子 |
|---|---|---|
| 应用级元数据与配置 | `AppInterface`、`switon.yml`、env | app id、env、debug、timezone |
| 请求输入与请求元数据 | `RequestInterface`、request input objects、request attributes | 路由参数、header、一次请求的交接值 |
| 请求级可变 service 状态 | `Context` | 每请求工作变量、locale、trace、属于一个 service 的临时缓冲 |
| 跨请求或持久状态 | 持久化或存储边界 | 数据库记录、上传文件、session 登录态、队列任务 |

主要区分是**生命周期**。
数据必须超出当前逻辑请求 → 不属于 `Context`。
只属于当前逻辑请求但要在多个 service 方法间共享 → `Context` 常常是正确 owner。

## 何时 request attributes 就够 {#attributes-enough}

不要一上来就用 `Context`。
若值只是 HTTP 边界上的一次请求交接，request attributes 往往更简单。

request attributes 是**仅 HTTP** 的交接面。CLI、schedule、queue 或 worker 路径里，要么用显式方法参数，要么把请求级可变状态放进 `Context`。

默认：

- **request attributes** — 在 HTTP 各阶段传递的简单请求绑定元数据
- **`Context`** — 长驻 service 在一次逻辑请求中跨多次方法调用需持有可变状态时

让方案和问题规模相称。

## 何时使用 Context {#context}

当以下条件成立时使用 `Context`：

- 数据是**可变的**
- 数据属于**一次逻辑请求或协程作用域**
- 数据属于**一个 service 边界**，而非全局应用配置或持久存储
- 直接放在共享 service 上不安全或易误导

面向应用的规则保持简短：

- 创建 `*Context` 类
- 在 service 上实现 `Switon\Core\ContextAware`
- 注入 `Switon\Core\ContextManagerInterface`
- 在 service `getContext()` 中返回 `$this->contextManager->getContext($this)`
- 通过该 service 级 `getContext()` 读写状态

子协程需要独立临时状态而非共享继承状态时，使用 `Switon\Core\ContextIsolated`。

owner 指南见 [Context](../context/README.md)。

## 安全默认规则 {#safe-default-rules}

这些默认能让多数应用代码远离麻烦：

- **尽可能让 service 无状态。**
- **不要在共享 service 属性上直接存请求级可变字段。**
- **简单的一次请求交接用 request attributes。**
- **一个 service 需要在多次方法间持有请求级可变工作态时用 `Context`。**
- **数据必须超出当前请求或进程时用真实存储。**
- **只有当子级局部状态确实不能共享时才用 `ContextIsolated`。**

若只记一条：

> 状态生命周期决定 owner
