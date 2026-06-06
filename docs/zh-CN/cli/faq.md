---
sidebar_label: CLI FAQ
description: CLI：编写命令、发现、选项与补全。
---

# CLI FAQ {#cli-faq}

面向编写、发现与运行时问题的短答。

> **前置：** [CLI 包](README.md#cli-package)

## 入门 {#getting-started}

### 怎么加一条命令？ {#add-command}

在 `App\Command`（或 Areas 下的命令路径）里放 `*Command` 类，用公有 `*Action()` 暴露动作，经 `bin/console` 调用。见 [快速开始](README.md#quick-start)。

### 怎么让裸 `command` 能用？ {#bare-command}

在命令类里加 `defaultAction()`。裸 `command` 会路由到它。见 [形态与路由](README.md#shape-and-routing)。

### 选项怎么进动作参数？ {#options-into-action}

先绑具名选项，再用位置参数按声明顺序补齐。见 [参数绑定](README.md#parameter-binding)。

### 支持向命令能藏起来吗？ {#hide-command}

可以。在类或动作上加 `#[Switon\Command\Attribute\Hidden]`；`list --all` 仍会列出。

### 为什么 `list` 里没有我的命令？ {#missing-from-list}

通常是未发现或被隐藏。先核对扫描路径与 `extra.switon.commands`，再跑 `bash bin/console list --all`。见 [发现](README.md#discovery)。

## 输出与工具面 {#output-and-tooling}

### 怎么输出或读用户输入？ {#console-input-output}

注入 `Switon\Core\ConsoleInterface`，调用其输出与询问方法。

### 怎么加进度条？ {#progress-bar}

注入 `Switon\Console\ProgressBarInterface`。

### 怎么开 Shell 补全？ {#shell-completion}

执行 `bash bin/console completion:install` 生成脚本，再用 `bash bin/console completion:check` 校验。

### `#[Tool]` 会改变命令行为吗？ {#tool-metadata}

不会。只为读元数据的 AI/工具注册附一段说明。写命令与调用方式不变。
