---
sidebar_label: Queue FAQ
description: Queue：migrateDue、未映射队列 push、concurrency 与 queue:work。
---

# Queue FAQ {#queue-faq}

**任务、信封、延迟与 Worker 相关的短答。**

> **阅读前提：** [Queue 包](README.md)

## 入门 {#getting-started}

### 怎么注册任务？ {#register-job}

在实现 `Switon\Core\RunnerInterface` 的类上添加 `#[Queued('queue-name')]`。

### 发现从哪扫？ {#discovery-sources}

默认扫应用 Job 路径和 Composer `extra.switon.jobs`。多个 `#[Queued]` 时只用**第一个**；空名跳过。

### `QueueEnvelope::of()` 填什么？ {#envelope-of}

`body` 和 `headers`；协议级字段直接在信封上设置。

## 行为 {#behavior}

### `pull()` 会迁移延迟任务吗？ {#pull-delayed}

**不会。** 必须 `migrateDue()`。`pull` 用 `BRPOP`；`timeout=0` 时阻塞等待。

### `consume()` 做什么？ {#consume}

解析队列对应 Runner，然后委托 **executor** 执行。

### `#[Queued]` 的 `concurrency`？ {#queued-concurrency}

选择该任务的 executor 路径；**不改变**队列路由或 worker 归属。

### 推到未映射队列？ {#unmapped-queue}

`push()` **仍会成功**；`consume()` 时因为找不到 Runner 而失败。

### 队列名能以 `.` 开头吗？ {#dot-queue}

可以；此类名跳过统计计数。

## Worker {#workers}

### 要单独 migrator 进程吗？ {#separate-migrator}

不强制，但必须有循环调用 `migrateDue()`。

### 有哪些 CLI？ {#cli-actions}

`queue:stats`、`queue:push`、`queue:run`、`queue:work`、`queue:migrate-due`。`queue:run` 是短窗口拉取；`queue:work` 可以 `timeout=0` 阻塞。

### stats 里的 `failed`？ {#failed-stats}

反映 Redis 中的 failed 状态；传输层**不写入** failed 项。
