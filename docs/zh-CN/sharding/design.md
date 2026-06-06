---
sidebar_label: Sharding 设计说明
description: 显式路由与快速失败；非透明分布式 SQL。
---

# 设计说明 {#design-rationale}

`switon/sharding` 让分片路由**显式**、**快速失败**，而不是伪装成单库 SQL。

## 参见 {#see-also}

- [Sharding 包](README.md) — 规则、配置与限制
- [FAQ](faq.md#sharding-faq) — 常见问题
- [ORM 分片指南](../orm/sharding.md#sharding-guide) — 实体元数据与仓储行为

---

## 要解决的问题 {#core-problem}

分片假设容易藏在查询辅助函数、命名约定与部署细节里，事后难以审计。

## 首要目标 {#primary-goal}

解析分片目标并校验边界：

- 意图可见；
- 单分片操作在歧义时立即失败；
- 扇出必须是调用方显式选择的结果。

## 取舍 {#trade-off}

- **好处：** 路由决策更易审查。
- **好处：** 不安全 join 尽早失败。
- **代价：** 调用方须在 `unique()`、`multiple()` 与 `all()` 之间做出选择。
- **代价：** 不把分布式 SQL 当成单一透明数据库。

## 刻意限制 {#deliberate-limits}

- 无分布式事务；
- 无跨分片 join；
- 不创建数据库或表；
- 无自动迁片计划。
