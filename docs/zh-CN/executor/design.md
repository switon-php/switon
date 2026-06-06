---
sidebar_label: Executor 设计说明
description: 为何统一 Runner 边界、任务 ID 拆分与双批量模式，队列在包外。
---

# 设计说明 {#design-rationale}

`Executor` 在串行与协程运行时之间统一 **Runner 边界**，把任务身份、批量结果与生命周期可见性收进一条契约；传输、调度与监管留在包外。

## 参见 {#see-also}

- [Executor 包](README.md#executor-package) —— 用法、运行时行为与异常
- [FAQ](faq.md#executor-faq) —— 任务 id、运行时与排错

---

## 为何要有这一层 {#why-this-exists}

同一类工作常由不同触发器启动，但各触发器容易各自发明执行语义：

- 有的只按类名路由；
- 有的对部分失败处理不一；
- 有的不给结果句柄；
- 有的只在触发边缘可观测，看不到真实 `run()` 边界。

`Executor` 先收敛这种漂移。

## 规范了什么 {#what-switon-standardizes}

这些入口对齐到同一形状：

- `ExecutorInterface`
- `FutureInterface`
- `RunnerStarting` / `RunnerFinished`

运行时切换时，调用方行为仍可读、可预期。

## 留在外面的 {#what-stays-outside}

本包**不**拥有持久队列、重试、进程监管或传输策略。

那些由应用或其它包承担；框架只规范 **run 边界** 与面向调用方的 API。

## 为何拆分任务字符串 {#why-the-task-split-exists}

`:` 前一段解析 runner 服务；**整串**作 `submit()` 门控键。

于是一个 runner 服务可承载多条命名任务流，而不必为每条流单独绑一个服务。

## 为何有两种批量模式 {#why-there-are-two-batch-modes}

`invokeAll()` — 每槽一个 future，**全部**结果可见。

`invokeAny()` — 优化「首个成功」，失败尝试记为跳过而非整批终止。

## 为何事件包住 run 边界 {#why-events-wrap-the-run-boundary}

可观测性应围绕 `RunnerInterface::run()`，而不只在最外层触发器。

事件对让其它包共享一条执行级生命周期流，而无需重复计时逻辑。
