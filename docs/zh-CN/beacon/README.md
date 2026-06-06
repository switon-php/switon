---
sidebar_label: Beacon 包
description: Switon Beacon 包：面向应用的 AI 技能、标准入口与 CLI 发现命令。
---

# Beacon 包 {#beacon-package}

`switon/beacon` 是面向 **Switon 应用** 的可安装合集：把 AI 规则、技能、对标准文档的入口，以及一套 CLI 发现工具打成**一个 Composer 包**。

**关键特性：**

- **应用侧技能：** 安装后技能位于 `vendor/switon/beacon/skills`。
- **标准入口：** `vendor/switon/beacon/standards` 引导智能体去对接应用根目录的 `AGENTS.md`。
- **Composer 交付：** 与升级普通依赖一样更新规则与技能。
- **Switon 诊断：** 提供 `tool:*`、`config:*`、`class:*`、`di:*`、`event:*` 等发现类命令（具体以 `tool:commands` 为准）。

## 安装 {#installation}

**环境要求：** PHP 8.3+

```bash
composer require --dev switon/beacon
```

## 快速开始 {#quick-start}

安装后，把智能体/IDE 的引导指向 **vendor 里已安装的包**。

```bash
bash bin/console tool:list
bash bin/console tool:entry event
bash bin/console config:check switon.yml config
```

- 技能目录：`vendor/switon/beacon/skills`。
- `vendor/switon/beacon/standards` 用来连到你应用根目录的 `AGENTS.md` 约定。
- 仓库根下给**框架开发**用的 `skills/` **不在**安装进应用的 Beacon 包内。
- `tool:list` 只列简短的 `#[Tool]` 摘要。
- `tool:commands` 列出完整 CLI 命令面。

## 术语速览 {#terms}

- **skills：** 随包提供的、给智能体加载的技能。
- **standards：** 随包提供的引导，指向应用根 `AGENTS.md`。
- **tool:\***：对应当前应用 CLI 面的 JSON 发现命令。
- **AGENTS.md：** 通常用来把应用接到 Beacon。

## 配置 {#configuration}

Beacon 由 **智能体/IDE 引导文件** 加载，而不是 `switon.yml`。

- 应用根 `AGENTS.md`
- 本机智能体或编辑器配置
- 工具链里解析 `vendor` 路径的方式

安装后执行 `bash bin/console tool:commands` 可查看完整命令表。

工具调用还会写入**脱敏**审计日志：`@runtime/beacon/tool.log`。

## 使用注意 {#keep-in-mind}

- 把路径指到**已安装**的 `vendor/switon/beacon/`，不要随意指到本仓库里单独的 `skills/` 拷贝。
- 把 Beacon 当成 **AI 引导与技能包**，不是通用业务运行时库。
- **每个应用装一份**即可，不必把包文件再复制一份塞进项目里维护。

## 下一步 {#next}

- **常见问题：** [FAQ](faq.md#beacon-faq)
- **边界与设计：** [设计说明](design.md#design-rationale)
