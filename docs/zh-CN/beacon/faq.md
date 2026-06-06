---
sidebar_label: Beacon 常见问题
description: Beacon 包安装位置、更新方式、tool 命令与排错。
---

# Beacon FAQ {#beacon-faq}

安装与包边界相关的短问答。

## 入门 {#getting-started}

### Beacon 是做什么的？ {#beacon}

面向 Switon 应用的、可安装的 **AI 规则与技能** 合集（与应用代码一起版本化）。

### Beacon 和仓库里那个 `skills/` 是一回事吗？ {#root-skills}

不是。Beacon 是 **`composer install` 后**落在 `vendor` 里、给**应用开发**用的那一层。仓库里的框架向 `skills/` 是给**框架开发**用的另一条线。

### 要不要在 `switon.yml` 里加载 Beacon？ {#switonyml}

AI 引导这层不走 `switon.yml`，从 `AGENTS.md` 或本机智能体配置指过去即可。包里的 PHP 命令和监听器仍会通过 Composer **正常注册**。

## 使用包 {#package-use}

### 装好后技能在哪？ {#where-skills-live}

在 `vendor/switon/beacon/skills`。`vendor/switon/beacon/standards` 用来帮你接到应用根目录的 `AGENTS.md`。

### 怎么升级 Beacon？ {#how-to-update}

像升级其它 Composer 依赖一样 `composer update`（或锁定版本后照常升级）。

### `tool:commands` 会显示什么？ {#tool-commands}

**完整** CLI 命令面，包含 `code:*`、`path:*`、`packages:*`、`composer-extra:*` 等族。`tool:list` 只有 `#[Tool]` 的短摘要。

### Beacon 会记工具调用日志吗？ {#tool-invocation-log}

会。`ToolInvoking` 相关事件会写**脱敏**审计到 `@runtime/beacon/tool.log`。

## 排错 {#troubleshooting}

### 智能体读不到 Beacon 规则，先查什么？ {#agent-not-seeing}

确认已安装 `switon/beacon`、磁盘上存在 `vendor/switon/beacon/`，且引导配置指向该路径。

### 能直接改 `vendor/` 里的 Beacon 吗？ {#edit-vendor}

不建议。应用特有规则放在**应用自有**的仓库与文件里；官方 Beacon 有变更时应升级 Composer 依赖或向上游贡献，不要依赖在 `vendor/` 里长期手改。
