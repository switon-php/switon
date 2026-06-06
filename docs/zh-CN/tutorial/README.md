---
description: 按一条有序教程路径，从首个 HTTP 路由走到服务、持久化、CLI 复用与日志。
---

# 教程 {#tutorial}

面向能读 PHP、但尚未建立框架心智模型的开发者，通过一条有序示例路径学习 Switon。

**预期：**
- 你将构建一个从 HTTP 到日志的小型类应用示例。
- 开始之前**不必**先学完所有包。
- 需要更深行为或配置细节时，再切换到对应包的文档。

## 从这里开始 {#start-here}

若本地运行环境尚未就绪，从 [安装 Switon](../getting-started/install.md#install) 开始。若 shell 仍不可用，阅读 [环境准备](../getting-started/environment.md#environment)。若尚未验证全新应用能通过 HTTP 响应，先做 [首个 Hello World 检查清单](../getting-started/first-hello-world.md#first-hello-world)。

若 `boundary`、`runtime`、`Context` 或 `worker` 等词仍会拖慢阅读，可常备 [面向初学者与译者的术语表](../concepts/glossary.md)。若你已明确要做的任务，改用 [指南](../guides/README.md#guides)。否则，请按下方章节顺序继续。

在下一问题仍是「我还不知道正常的 Switon 形态」时使用本路线。把问题收窄为：

- 一个请求如何到达一个 action？
- 输入如何变成类型化且已校验的数据？
- 可复用的业务逻辑放在哪里？
- 持久化数据如何进入上述形态？
- 同一套业务逻辑如何从 CLI 运行？
- 出问题时先看哪里？

若某一章已能回答你当前问题，先完成该章，再打开更多包文档。

路线选择：

- **用本教程** — 需要一条持续演进的示例，且每章代码变化明确。
- **用 [构建 API](../guides/build-an-api.md#build-an-api)** — 已熟悉基本 Web 应用结构，想要第 `01` 至 `03` 章的压缩版。
- **先用 [核心概念](../concepts/README.md#switon-fundamentals)** — 想在第 `01` 章之前先理解框架方法论。

## 主路径 {#main-path}

主路径构建一个小型任务管理 API。每章增加一个新边界，并保留前一章成果。

1. [01 — HTTP 端点](01-http.md#01-http) — `HTTP`、`Router`、控制器、JSON 响应
2. [02 — 整洁输入](02-input.md#02-input) — 请求输入对象、`Invoker`、`Validation`、可读字段标签
3. [03 — 服务边界](03-service.md#03-service) — 服务、`DI`、控制器边界
4. [04 — 存储数据](04-data.md#04-data) — `ORM`、实体与仓储角色、显式持久化
5. [05 — CLI 复用](05-cli.md#05-cli) — 在同一服务层上运行控制台命令
6. [06 — 日志与失败](06-log.md#06-log) — `Log`、失败可见性、基础排障习惯

此顺序有意为之：

- 第 01 章：打通一条 HTTP 路径
- 第 02 章：让输入更易读、可信、可向用户解释
- 第 03 章：把可复用工作移入服务
- 第 04 章：让任务数据在重启后仍存在
- 第 05 章：从 CLI 复用同一服务
- 第 06 章：加入日志，便于理解失败

## 旁路 {#side-paths}

主路径完成后再用。它们在同一示例上扩展，不加重首轮负担。

- [定时清理](timed-cleanup.md#timed-cleanup) — CLI 与服务边界清晰后，用 `Schedule` 运行周期性清理
- [更快的列表](faster-list.md#faster-list) — 存储数据已工作后，用 `Cache` 缓存读密集的任务列表

旁路为可选。先完成主路径，再选与下一学习目标匹配的一篇。
