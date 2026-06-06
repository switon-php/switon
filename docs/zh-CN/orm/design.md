---
sidebar_label: ORM 设计说明
description: 显式持久化、无属性懒加载、分片写入须单一目标。
---

# 设计说明 {#design-rationale}

`switon/orm` 通过**显式**读写、关联加载与分片路由，让持久化行为可审查。

## 参见 {#see-also}

- [ORM 包](README.md) — 用法、概念与首次接入
- [FAQ](faq.md) — 短答与常见坑
- [关联指南](relations.md) — 关联形状与加载示例

---

## 为何存在 {#why-this-exists}

各模块若自造读/写/加载风格，数据访问难以 code review。Switon 以 **Repository** 与**显式持久化**标准化该边界。

## 主要取舍 {#main-trade-off}

选择**显式持久化**，而非隐藏状态：

- 内存中的实体变更**不会**意外 flush；
- 关联**仅在请求时**加载；
- Repository 是应用常态边界；
- 同一行可能对应多个实体实例；
- 关联实体通过显式调用持久化，**无**级联魔法。

行为更易推理，代价是便利魔法更少。

## 关联加载 {#relation-loading}

加载形状写在 Repository 调用里，而非属性触发懒加载——查询成本与已加载数据更可预测。

## 状态与路由 {#state-and-routing}

分片路由显式，而非藏在 Unit of Work 式 flush 后：

- 读可按需扇出；
- **写**必须解析到单一目标；
- 批量写须已分片安全。

跨分片行为可落地，且写入结果不模糊。
