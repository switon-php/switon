---
sidebar_label: Command FAQ
description: Command：发现、可见性、元数据与内建命令兜底。
---

# Command FAQ {#command-faq}

围绕发现、可见性与元数据的短答。

> **前置：** [Command 包](README.md#command-package)

## 入门 {#getting-started}

### 怎样让命令可被发现？ {#make-discoverable}

类名用 `*Command` 结尾，放在应用的扫描路径下；包自带命令则列在 `composer.json` 的 `extra.switon.commands`。见 [发现](README.md#discovery)。

### 动作怎么变成可运行入口？ {#actions-runnable}

用公有的 `*Action()` 方法。`defaultAction()` 是裸命令入口的惯用名；具体路由由 CLI 处理。

### 怎么隐藏支持向动作？ {#hide-action}

在类或方法上加 `#[Switon\Command\Attribute\Hidden]`。仍可运行，只是默认列表不展示。

### 怎么加 AI 简介？ {#add-tool-synopsis}

在动作上加 `#[Switon\Command\Attribute\Tool('...')]`，一句话说明动作做什么即可。

## 元数据 {#metadata}

### 怎么设短选项别名？ {#short-option}

在动作参数上加 `#[Switon\Command\Attribute\ShortOption('x')]`。检视器只带元数据；CLI 解析 argv 时使用。

### 帮助文案从哪来？ {#help-text}

类 docblock 首句 → 命令摘要；动作 docblock 首句 → 动作摘要。`@param` 填参数帮助。

### 发现会「注册」命令吗？ {#discover-register}

不会。`CommandDiscoveryInterface::discover()` 只返回缓存的名字到类的映射。接执行是消费者的事（控制台走 CLI，AI 工具走 Beacon）。

### 为什么 composer-extra 坏了还能看到 `list`、`help`、`completion`？ {#builtin-fallback}

`CommandDiscovery` 把内建 CLI 命令保留在映射里作兜底，这样在修 composer 元数据期间控制台仍可用。

## 下一步 {#next}

- **完整写命令范式：** [README](README.md#command-package)
- **设计边界：** [设计说明](design.md#design-rationale)
- **运行命令与查看帮助：** [CLI 包](../cli/README.md)
