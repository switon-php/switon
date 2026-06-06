---
sidebar_label: Executor FAQ
description: Executor：invoke/submit/invokeAll/invokeAny、任务 ID、门控与 Future 排错。
---

# Executor FAQ {#executor-faq}

**任务 id、运行时模式与错误处理的短答。**

> **前置：** [Executor 包](README.md#executor-package)

## 入门 {#getting-started}

### `invoke()` 做什么？ {#invoke-sync}

在当前流程执行单个任务，runner 失败会重抛。

### 何时用 `submit()`、`invokeAll()`、`invokeAny()`？ {#which-method}

- 调用方**不需要**同流程拿结果 → `submit()`；
- **每个**结果都要看 → `invokeAll()`；
- **首个成功**就够 → `invokeAny()`。

### 任务 id 可以写什么？ {#task-id}

任意能解析为 `RunnerInterface` 的容器 id；通常用 runner 的 FQCN 最清晰。仅当同一 runner 要多个 `submit()` 桶时加 `:suffix`。

只有**第一个** `:` 拆分 runner id，后面的 `:` 属于后缀。

### `submit()` 一定后台跑吗？ {#submit-background}

不一定。仅**协程运行时**会调度到后台；串行运行时仍在当前流程执行。

`invokeAll()` / `invokeAny()` 不使用 `submit()` 门控。

### `invokeAny()` 会取消其它任务吗？ {#invoke-any-cancel}

不会。首个成功即返回，但已启动的协程任务会继续跑。

返回的是**首个成功**的结果，不一定是输入顺序里的第一项。

## 运行时 {#runtime}

### `submit()` 第三参做什么？ {#limit-concurrency}

限制该任务字符串下在途协程提交数。**小于 `1`** 关闭门控。仅在协程运行时生效。

### 批量失败怎么读？ {#batch-failures}

用 `invokeAll()`，逐项看 `FutureInterface`；失败任务的 throwable 在 `error()`。

### `invokeAll()` 会在首个失败时停下吗？ {#invoke-all-stop}

不会。每个输入槽各有一个 future，失败分别记录。

### 全部失败时 `invokeAny()` 返回什么？ {#invoke-any-null}

`null`。

### 调度失败会怎样？ {#scheduling-failure}

`submit()` 抛 `TaskSchedulingException`；`invokeAll()` 记在对应 future 上；`invokeAny()` 继续找其它成功项。

runner 抛错时仍会发 `RunnerFinished`；`invoke()` 的调用方会收到重抛的异常。

## 排错 {#troubleshooting}

### 为何 `TaskRunnerException`？ {#task-runner-exception}

解析到的服务未实现 `Switon\Core\RunnerInterface`。

### 为何 `InvalidTaskException`？ {#invalid-task-exception}

任务 id 为空，或 `:` 前前缀为空。
