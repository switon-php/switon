# Overview

本文档说明 Arcus 内容系统的关系型设计范围与目标。

## Scope

- 使用 Arcus 自有语义作为主设计依据。
- 关系由应用层维护，不在数据库层声明外键。
- 文档只定义稳定结构与规则，不绑定具体实现框架。

## Core Model

- 内容主实体：`post`、`page`
- 版本实体：`snapshot`
- 分类体系：`taxonomy`、`category`、`tag`
- 互动实体：`comment`、`reply`
- 附件与扩展：`attachment`、`meta`、`setting`

## Design Priorities

- 简化：避免重复字段与多套等价状态。
- 可演进：字段名聚焦业务语义，不绑定存储实现细节。
- 可查询：面向主路径（列表、详情、发布任务）优化。
- 可维护：通过分档文档降低评审和变更成本。

## Delivery Phasing

- 先稳定 schema 与约束。
- 再补发布流程与查询口径。
- 最后进入 migration、实体映射和功能设计。
