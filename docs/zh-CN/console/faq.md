---
sidebar_label: Console FAQ
description: Console：进度条、着色、绑定与宽度缓存。
---

# Console FAQ {#console-faq}

安装与常见终端输出问题的短答。

> **前置：** [Console 包](README.md#console-package)

## 入门 {#getting-started}

### 怎么加进度条？ {#add-progress-bar}

注入 `Switon\Console\ProgressBarInterface`，依次调用 `start($total)`、`advance()`、`finish()`。只有确实不知道总量时才省略总数；默认 `max` 为 `1`。

### 怎么给终端输出上色？ {#color-output}

把 `Switon\Console\Colors` 常量与 `Switon\Core\ConsoleInterface::colorize()` 组合使用。渲染依赖 `ConsoleInterface` 实现，例如 `Switon\Cli\Console`。

### 本包会自己注册绑定吗？ {#bindings}

不会。只提供原语。`switon/cli` 为写命令接好接口；其它场景由应用容器决定。

## 行为 {#behavior}

### 为什么终端宽度不变？ {#width-cache}

`TerminalWidthDetector` 缓存第一次可用的宽度。长期进程若在终端缩放后仍要正确换行，应在响应 `SIGWINCH` 等时机调用 `clearCache()`。

### 为什么进度条一下子到头？ {#bar-jumps}

`setProgress()` 会夹到 `max`，`finish()` 会在 100% 做最后一次渲染。给 `start()` 传入真实总量可避免「突然跳满」的观感。

### 可以不用 `switon/cli` 吗？ {#without-cli}

可以，但仍需某种 `Switon\Core\ConsoleInterface` 实现才能把带颜色的文本写到终端。
