---
sidebar_label: Queue 设计说明
description: 传输、发现、延迟迁移与执行分层；不拥有重试/DLQ。
---

# 设计说明 {#design-rationale}

`switon/queue` 把**传输、发现、延迟迁移和执行**分开。

## 参见 {#see-also}

- [Queue 包](README.md) — 用法、队列信封与 Worker 入口
- [Queue FAQ](faq.md#queue-faq) — 短答与常见行为

---

## 要解决的问题 {#core-problem}

如果一层试图包办一切，队列系统很容易失控：

- 传输层开始隐藏 worker 策略；
- 任务发现看起来像运行时注册表；
- 延迟投递变成副作用；
- 重试和失败策略消失在传输代码里。

本包让各层之间的交接方式保持可见。

## 为何拆分 {#split}

- `Queue` 在 Redis 上移动信封。
- `JobDiscovery` 将队列名解析为 Runner 类。
- `migrateDue()` 按明确节奏让延迟工作变为就绪。
- `consume()` 将执行委托给 executor 层。

职责更小，也更容易替换。

## 发现为何严格 {#discovery-strict}

一个队列名对应一个 Runner 类。

这让投递更可预测，也避免多处理器列表竞争。`concurrency` 留在发现层，用于选择 executor 路径，而不是把队列变成 worker 监管。

## 延迟迁移为何显式 {#delayed-migration-explicit}

延迟消息会留在 Redis 中，直到 worker 或 migrator 调用 `migrateDue()`。

这样延迟投递在运维上更可见，也把消费者延迟和调度工作分开了。

## 刻意不拥有 {#outside}

本包**不**拥有：

- 重试策略；
- 死信策略；
- 恰好一次投递；
- 队列级限流；
- 超出已随包提供的 CLI 入口之外的 worker 编排。

这些由应用与部署拓扑决定。
