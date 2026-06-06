---
sidebar_label: Invoker FAQ
description: Invoker：invoke 与直接调用、Binding 分工、钩子与测试。
---

# Invoker FAQ {#invoker-faq}

面向 callable 执行、钩子与 dispatch 边界的短答。

> **前置：** [Invoker 包](README.md#invoker-package)

## 入门 {#getting-started}

### 什么时候用 invoker 而不是直接调用？ {#direct-call}

需要一次带 DI 回退、guard 或 interceptor 的**受管**最终调用时，使用 `Switon\Invoking\InvokerInterface`。见 [快速开始](README.md#quick-start)。

只要底层 callable 执行、不要方法级钩子时，用 `Switon\Di\InvokerInterface`。Guard 与 interceptor 契约在 [Invocation 包](../invocation/README.md#invocation-package)。

### invoker 能从请求输入拼参数吗？ {#build-arguments}

不能。参数仍来自输入、类型化输入或 builder 时，先用 [Binding 包](../binding/README.md)。

### 支持哪些 callable 形态？ {#callable-shapes}

数组 callable、闭包、字符串 callable、可调用对象均可。见 [可调用边界](README.md#callable-boundary)。

## 边界 {#boundaries}

### 参数值从哪来？ {#parameter-values}

Invoker 侧顺序为：显式覆盖、默认值、类型键对象、DI 回退。见 [参数解析](README.md#argument-resolution)。传输层输入属于 [Binding 包](../binding/README.md#binding-package)。

### 为什么直接调用不走 guard/interceptor？ {#direct-hooks}

钩子只在 `invoke()` 管道中运行。`preHandle()` 返回 `false` 不会跳过调用；应抛异常阻止。`postHandle()` 可通过引用替换返回值。

### invoker 负责 CLI 解析吗？ {#cli-parsing}

不负责；CLI 解析在 `switon/cli`，invoker 只执行最终 callable。

### 需要包级配置吗？ {#configuration}

不需要。Invoker 走常规 DI 接线，**不**额外提供选项面。

## 排错 {#troubleshooting}

### 为什么对象参数来自容器？ {#object-container}

内置 DI 回退。若期望先走 binding 管道，请经 [Binding 包](../binding/README.md) 解析参数。

### 怎么测使用 invoker 的代码？ {#testing}

业务逻辑可直接测；guard/interceptor 行为在受管 invocation 场景下经 `Switon\Invoking\InvokerInterface` 测。
