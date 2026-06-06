---
sidebar_label: Schedule 设计说明
description: 统一任务表与应用时钟；非完整 crontab。
---

# 设计说明 {#design-rationale}

`switon/schedule` 把周期性工作留在**应用模型**内，避免 cron、worker 与隐藏锁策略各管一套。

## 参见 {#see-also}

- [Schedule 包](README.md) — 用法、配置与运维
- [FAQ](faq.md#schedule-faq) — 常见问题

---

## 要解决的问题 {#core-problem}

任务有的在 cron、有的在 worker 循环、时钟规则还因机器而异时，周期性逻辑会漂移。

## 首要目标 {#primary-goal}

统一**一张任务表、一个应用时钟、一条执行路径**：

- 任务仍是容器管理的类；
- 表达式短、基于墙钟；
- 分布式锁显式可选（`lockTtl`）。

## 取舍 {#trade-off}

- **好处：** 调度在应用代码中可见。
- **好处：** 运维只需核对一个应用时区。
- **代价：** 这不是完整 crontab 语法。
- **代价：** 分布式锁为可选能力。

## 刻意限制 {#deliberate-limits}

- 无 per-task 时区；
- 无月/星期字段；
- HTTP 服务不自动调度；
- `lockTtl: 0` 时不隐含副本互斥。
