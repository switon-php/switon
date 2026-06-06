---
sidebar_label: Schedule FAQ
description: Schedule：四段表达式、时区、lockTtl 与 schedule:run/work。
---

# Schedule FAQ {#schedule-faq}

**定时任务接线与运维相关的短答。**

> **阅读前提：** [Schedule 包](README.md)

## 入门 {#getting-started}

### 如何新增定时任务？ {#add-task}

创建实现 `Switon\Core\RunnerInterface` 的类，添加 `#[Scheduled(...)]`，并放在已扫描的任务路径下。

### 表达式格式？ {#expression-format}

四段：`秒 分 时 日`。每段仅 `*`、整数或 `*/n`。不支持列表、区间、星期、月份。

### 能否 per-task 时区？ {#per-task-timezone}

**不能。** 统一使用 `AppInterface::timezone()`。

### 生产环境怎么跑？ {#run-production}

cron 调 `schedule:run`，或常驻 `schedule:work`。

### 如何立刻跑某一个任务？ {#task-id-run}

`schedule:list` 查名称 → `schedule:task <name>`（**不**检查表达式是否到期）。

## 配置 {#configuration}

### 发现路径在哪配？ {#configure-tasks}

`switon.yml` 里 `Switon\Schedule\TaskDiscoveryInterface` 的 `tasks`。

### 如何查看选项？ {#discover-options}

`bash bin/console config:options 'Switon\Schedule\TaskDiscoveryInterface'`

### 全是 `lockTtl: 0` 为何还要 Redis？ {#redis-binding}

默认 `Lock` 实现仍需要容器里存在 `redisLock` 绑定。

### 如何调节 `schedule:work` 频率？ {#work-tick}

配置 `Switon\Schedule\Command\ScheduleCommand` 的 `tick`（**毫秒**）。

## 运维 {#operations}

### HTTP 会自动跑调度吗？ {#http-server}

**不会。** 须独立调度进程。

### 到期任务会并行吗？ {#parallel}

启用协程运行时**可能**并行执行多个到期任务。

### 有哪些事件？ {#events}

`Switon\Schedule\Event\TaskStarted`、`Switon\Schedule\Event\TaskFinished`（INFO，含 `immediate`）与 `Switon\Schedule\Event\TaskFailed`（ERROR，携带可抛出对象）。

## 排错 {#troubleshooting}

### 任务从不执行 {#never-runs}

查表达式、应用时区、`schedule:list`、是否被其他副本 `lockTtl` 锁住。

### 多机重复执行 {#duplicate-runs}

设正数 `lockTtl` 并保证 Redis 正常。

### 重复 task id 注册失败 {#duplicate-task-id}

重命名或改命名空间；id 由**类名**推导，与 HTTP handler id 规则无关。

### `schedule:work` 立刻退出 {#work-fails}

缺少协程运行时支持（如未启用 Swoole 等）。
