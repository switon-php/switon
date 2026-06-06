---
sidebar_label: ID 设计说明
description: 单一契约 + 命名 DI；算法辅助方法留在具体类上。
---

# 设计说明 {#design-rationale}

`switon/id` 在 DI 里**显式**选择 ID 算法，同时把算法特有辅助留在具体生成器上，不假装统一 API。

## 参见 {#see-also}

- [ID 包](README.md#id-package) — 选型、配置与运行时边界
- [FAQ](faq.md#id-faq) — 常见问题与排错

---

## 要解决的问题 {#core-problem}

ID 选择很快会牵动存储与对外 API 假设：

- 一处要可排序整数；
- 另一处要 UUID 兼容字符串；
- 第三处要紧凑公开 token；
- 算法元数据不应伪装成同一套通用接口。

本包让选择在调用点**可见**，又不必每个调用点都了解算法细节。

## 首要目标 {#primary-goal}

先标准化**小契约**，再由 DI **按名**选具体生成器。

- **好处：** 服务代码形态统一；
- **好处：** 默认路径简单；
- **代价：** 算法特有方法留在具体类，不进共享接口。

## 默认优先 {#default-first}

未命名绑定 → **`uuid4`**。

- 需要顺序 → `uuid7` 或 `ulid`；
- 需要整数且能支撑 Redis → `snowflake`；
- 需要紧凑不透明文本 → `nanoId` 或 `cuid2`。

常见路径简单，更强约束显式选用。

## 具体辅助留在具体类 {#concrete-helpers-concrete}

共享接口刻意保持小：

- `Snowflake::parse()` 仅 Snowflake；
- `Ksuid::extractTimestamp()` 仅 KSUID；
- `MongoId::extractTimestamp()` / `isValid()` 仅 MongoId；
- `ShortUuid` 为静态 UUID 辅助。

代价是跨生成器统一性较弱，但避免虚假的「万能 ID API」。

## Snowflake 是例外 {#snowflake-exception}

唯一需要**外部协调**的内置生成器：

- Redis 分配序列；
- 进程内批量状态提高效率；
- 适合写密集的整数 ID，不适合作为最简单默认路径。
