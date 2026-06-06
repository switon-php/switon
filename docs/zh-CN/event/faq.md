---
sidebar_label: Event FAQ
description: Event：同步派发、监听器发现、event:listeners、EventLogger、StopFlow。
---

# Event FAQ {#event-faq}

**接线、发现与事件流相关的短答。**

> **前置：** [Event 包](README.md#event-package)

## 入门 {#getting-started}

### 怎么定义并派发？ {#create-and-dispatch}

定义事件类，注入 `Psr\EventDispatcher\EventDispatcherInterface`，调用 `dispatch()`。见 [快速开始](README.md#quick-start)。

### 监听器是同步的吗？ {#listeners-synchronous}

是。`dispatch()` 在当前进程与调用栈内执行。后台工作请在派发器**之外**再建显式异步边界。

### `dispatch()` 能返回业务数据吗？ {#return-value}

不能。只返回**同一事件对象**，不是监听器返回值。需要结果请用服务或查询对象。

## 配置 {#configuration}

### 监听器从哪发现？ {#discovery}

默认：`app/Listener/*Listener.php`、`app/Areas/*/Listener/*Listener.php`。包内还可来自 Composer `extra.switon.listeners`。

### 怎么查看已注册监听器？ {#inspect-listeners}

`bash bin/console event:listeners`，或 `bash bin/console event:listeners --filter '<event-fqcn>'`。这些命令来自 **Beacon**，不是 Event 包自带。

### 怎么看当前配置项？ {#discover-options}

`bash bin/console config:options 'Switon\Eventing\ListenerDiscoveryInterface'`  
`bash bin/console config:options 'Switon\Eventing\EventLoggerInterface'`

## 日志 {#logging}

### 怎么控制自动事件日志？ {#control-logging}

事件类实现 `Switon\Eventing\EventSilent` 可跳过日志；加 `#[EventLevel(Severity::INFO)]` 改级别；实现 `Switon\Eventing\EventLogInterface` 可做自定义格式。整个 `EventLogger` 可通过 `Switon\Eventing\EventLoggerInterface.enabled = false` 关闭。

### 何时用 `ObservabilityProbe`？ {#observability-probe}

监听器主要职责是日志、追踪或指标时。这类监听器与业务监听计数分开。

### 如何停止后续监听器？ {#stop-flow}

在监听器内抛出 `Switon\Core\StopFlow`。

## 排错 {#troubleshooting}

### 监听器没触发 {#listener-not-firing}

检查：方法为 public、有 `#[EventListener]`、**恰好一个**类型化参数、类在发现路径或已注册。无类型或交叉类型方法会在注册阶段快速失败。

### 父类能接住子类事件吗？ {#parent-types}

不能。要多事件请用联合类型或 `object`。

### 没接 listener provider 会怎样？ {#no-provider}

`dispatch()` 原样返回事件，不做其它事。
