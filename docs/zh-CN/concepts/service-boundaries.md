---
sidebar_label: 服务边界
description: 项目在 HTTP、CLI、周期任务与后台 worker 间增长前，应用代码应放在哪一层。
---

# 理解服务边界 {#service-boundaries}

在项目横跨 HTTP、CLI、周期工作与后台 worker 之前，先弄清各类应用代码应落在何处。

## 术语速览 {#terms}

术语陌生时，边读边开着 [面向初学者与译者的术语表](glossary.md)。

- **transport boundary** — 外层入口：HTTP、CLI、schedule、queue、RPC
- **service** — 可复用的业务逻辑边界
- **lifecycle listener** — 在 controller 调用前后运行的请求级 HTTP 逻辑
- **event listener** — 主业务 action 发出 typed 事件后的进程内反应
- **worker** — 常在 HTTP 外运行或受监管的长驻进程，在请求生命周期外处理后台工作

## 先记一条规则 {#one-rule-first}

若一段代码应从多个入口工作，它通常**不应**留在 controller action、console command 或 worker 循环里，而应放在 service。

稳定拆分：

- **controller、command、worker** 接收输入，协调传输/runtime 关切
- **service** 拥有业务决策与业务侧编排
- **listener** 拥有横切反应或请求级策略

拆分清楚后，多数 guide 更好读，因为每层问题更小。

## 放进最窄的 owner {#code-narrowest-owner}

默认放置规则：

| 关切 | 最佳 owner | 原因 |
|---|---|---|
| URL 路径、动词、状态码、重定向、下载 | controller / HTTP 边界 | 属于传输决策 |
| 请求体形状与边界校验 | request input object + validation | 输入应在边界失败 |
| 业务规则、持久化编排、mail 决策、可复用工作流 | service | 同一逻辑应可从多种传输调用 |
| 请求级 auth 加载、request id、CORS、访问日志 | lifecycle listener | 跨许多请求 |
| 仅包裹某一 action 的行为 | controller interceptor | 行为局部于一个 action 或 controller |
| 审计钩子、通知触发等进程内后续反应 | event listener | 反应应与主路径解耦 |
| 人工操作入口、定时入口、durable worker 入口 | CLI command / schedule / queue / RPC worker | 属于入口关切，而非业务模型本身 |

常见 HTTP 拆分：lifecycle listener 是请求级的；controller interceptor 局部于一个 action 或 controller；event listener 在应用代码发出 typed 事件后反应。

拿不准时问：若明天没有 HTTP，这层是否仍有意义？若业务能力仍在，多半属于 service。

## 一项业务、多种入口 {#business-many-entrypoints}

健康的 Switon 应用常通过多条入口复用同一 service。

例如同一「生成发票」能力可能来自：

- 管理员触发的 HTTP controller action
- 运维用的 CLI command
- 夜间处理的 scheduled task
- durable 后台的 queue worker

这些入口应保持薄：适配输入、runtime 或投递形状。业务规则本身不应重写四遍。

因此在 service 边界干净之后，[添加 CLI](../guides/add-cli.md#add-cli)、[添加周期性任务](../guides/add-recurring-work.md#add-recurring-work)、[添加持久化后台工作](../guides/add-durable-background-work.md#add-durable-background-work) 等指南才更好读。

## DI 让边界保持诚实 {#di-boundaries-honest}

依赖注入让这些边界可见。

普通应用代码中：

- 依赖接口或稳定包契约
- 用 `#[Autowired]` 声明依赖
- 避免把容器查找当作常态业务风格

这使业务代码显式声明所需，传输边界更薄。若 controller 或 worker 需要许多不相关的底层 service，还要兼管请求解析与响应塑形，边界通常过宽。

owner 指南见 [DI](../di/README.md)。

## 常见边界失误 {#common-boundary-mistakes}

留意这些漂移：

- **controller 变成 service 类** — 路由、校验、持久化、mail 全混在一个 action
- **service 解析 HTTP 细节** — header、cookie、响应决策渗入可复用业务代码
- **lifecycle listener 拥有业务工作流** — 请求级钩子做本应在 HTTP 外可调用的领域工作
- **command 或 worker 重写 controller 业务逻辑** — 入口复制 service 行为而非复用

修复通常不是加更多框架代码，而是把关切移回正确 owner。
