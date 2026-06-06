---
sidebar_label: Event 设计说明
description: 为何进程内同步派发、类匹配与 ObservabilityProbe，而非队列/传输层。
---

# 设计说明 {#design-rationale}

`switon/event` 让**进程内**反应保持小而显式，并与传输层、监控流水线分开。

## 参见 {#see-also}

- [Event 包](README.md#event-package) —— 用法、配置与主路径
- [FAQ](faq.md#event-faq) —— 常见问题

---

## 要解决的问题 {#core-problem}

应用里副作用常长成三种各写各的样式：

- 留在服务方法里内联；
- 挂成 listener；
- 推到异步传输。

Event 包在队列、RPC、工作流进来之前，先划清**一条进程内反应边界**。

## 规范了什么 {#what-it-standardizes}

- 类作事件身份与载荷；
- `#[EventListener]` 把注册贴在处理函数旁；
- 当前进程内同步 `dispatch()`；
- `object` 监听器做横切观察；
- `ObservabilityProbe` 区分日志/追踪/指标监听；
- `StopFlow` 有意提前结束；
- `EventLogger` 自动记事件而不另起一条管道；
- 注册事件与每次派发的可观测计数作诊断，**不是**第二层路由。

## 取舍 {#trade-offs}

常见路径保持简单，但本包**不是**队列、传输或重试系统。

- **好处：** 事件流易读、易调试；
- **好处：** 按类匹配，路由显式；
- **代价：** 异步投递与重试策略在包外；
- **代价：** 可观测性与业务反应要稍作区分。
