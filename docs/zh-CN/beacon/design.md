---
sidebar_label: Beacon 设计说明
description: 为何单独提供 Beacon：可版本化的应用侧 AI 引导与刻意划定的边界。
---

# 设计说明 {#design-rationale}

Beacon 解决的是：Switon 应用需要一层 **可随 Composer 升级**、**明确面向应用** 的 AI 引导（规则与技能），而不只是散落依赖。

## 参见 {#see-also}

- [Beacon 包](README.md) — 安装、是否适用、注意事项
- [FAQ](faq.md#beacon-faq) — 常见问题与排错

---

## Beacon 规范了什么 {#what-beacon-standardizes}

- **一个可安装的包：** 面向应用的 AI 规则与技能打在一起发布。
- **固定的 vendor 位置：** 技能与 standards 指针落在 `vendor/switon/beacon/`。
- **统一的更新路径：** 跟普通依赖一样走 Composer，变更可预期。

这样在多个项目之间复制「给智能体看什么」会轻松很多。

## 有意不包什么 {#deliberate-boundaries}

- **应用自有规则：** 仍放在应用仓与根目录 `AGENTS.md`，不要在 `vendor` 里长期改 Beacon 当应用配置。
- **框架开发用技能：** 仍在仓库独立的 `skills/` 工作流里，与装进应用的 Beacon 分层。
- **怎么加载：** 仍由应用选择 IDE/智能体如何引用 vendor 路径。

结果是：**交付方式一致**，又不强行让所有应用共用同一份应用侧业务规则。
