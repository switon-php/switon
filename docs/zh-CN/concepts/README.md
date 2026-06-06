---
sidebar_label: 框架基础
description: 了解 Switon 应用模型、请求流、服务放置与运行时安全边界。
---

# Switon 框架基础 {#switon-fundamentals}

在选任务指南或逐个打开包文档之前，先读这一页，建立 Switon 应用模型的整体图景。

## 从这里开始 {#start-here}

- 本地运行时尚未就绪 → [安装 Switon](../getting-started/install.md#install)
- shell 里还没有 `php`、`composer` 或 `git` → 先读 [环境准备](../getting-started/environment.md#environment)
- 若 `boundary`、`runtime`、`Context`、`worker` 等词拖慢阅读 → 边读边开着 [面向初学者与译者的术语表](glossary.md)
- 若你已明确要完成哪项任务 → 停在这里，改去 [指南](../guides/README.md#guides)
- 否则按下方顺序阅读基础概念

## 基础概念 {#fundamentals}

想用最短路径建立完整模型时，按这个顺序阅读：

1. **[理解 Switon 框架方法论](framework-methodology.md#framework-methodology)** — Switon 想统一什么、刻意留给应用什么
2. **[理解应用模型](application-model.md)** — 启动从哪开始、`Kernel` 管什么，以及 `switon.yml`、`.env`、`config/*.php` 如何配合
3. **[理解模块与 Area](modules.md)** — 功能代码如何归在 `app/Areas/<Area>/`，何时应做成可复用 module 或 package
4. **[理解配置与约定](config-and-conventions.md)** — `${APP_ENV:-prod}`、`#cache`、`@view`、同命名空间 `XxxInterface` → `Xxx` 等符号
5. **[理解请求生命周期](request-lifecycle.md)** — 一次 HTTP 请求如何从传输层到路由、入参绑定、业务代码与响应输出
6. **[理解服务边界](service-boundaries.md#service-boundaries)** — controller、service、生命周期 listener、事件 listener、command、worker 应在何处交接
7. **[理解运行时与状态](runtime-and-state.md)** — 为何单例 service 可保持安全、何时需要 `Context`、可变请求级状态应放哪

## Concepts 与 Guides {#concepts-versus-guides}

当下一个问题是**放置、词汇或框架模型**时用 concepts；是**具体应用任务**时用 guides。

- **Concepts 回答「该放哪」** — 例如代码属于 area、service、listener、command、job 还是 package
- **Guides 回答「下一步做什么」** — 例如搭第一条 API、加鉴权、持久化数据或加持久化后台任务

- 两者都相关时，只读到**放置决策清晰**为止
- 然后切换到对应 guide
- concepts 应缩短任务工作，而不是拖延它

## 应用形态 {#fits-together}

Switon 默认的应用模型小而明确：

1. `Kernel` 启动 env、config、应用元数据与 services
2. HTTP 或 CLI 等 runtime 通道接管
3. 面向传输的代码到达 controller、command 或 worker 边界
4. 业务工作留在 service，不散落在传输 handler 里
5. 横切反应在不应内联时移到 listener
6. 可变请求级状态不放在共享 service 属性上；需要时走 `Context`

- 这些概念页排在逐包阅读之前
- 它们解释让同一应用形态在 HTTP、CLI、事件与 worker 之间保持一致的那些框架规则
