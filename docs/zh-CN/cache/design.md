---
sidebar_label: Cache 设计说明
description: 为何绑定 Redis+JSON、PSR-16 边界与空前缀防护。
---

# 设计说明 {#design-rationale}

`switon/cache` 用 **Redis + JSON + PSR-16**，把常见应用缓存的默认形态固定下来：**键可读、值可巡检、`clear()` 有边界**。

## 参见 {#see-also}

- [Cache 包](README.md) — 用法与配置
- [FAQ](faq.md#cache-faq) — 常见问题

---

## 要解决的问题 {#core-problem}

缓存很容易长成：

- 各模块**键规矩不一**；
- 值变成**不透明二进制**；
- 共享 Redis 上 **`clear` 伤人伤己**；
- 小封装、小 helper 满天飞。

本包收窄默认行为，让「日常用法」更可预期。

## 首要目标 {#primary-goal}

优先服务**应用层**常见场景：

- **熟悉：** `Psr\SimpleCache` 这一套调用方式；
- **键：** 贴近 Redis 习惯；
- **值：** JSON，便于人肉看与其它语言读；
- **清理：** 必须带前缀，避免跨区域误删。

- **好处：** 大部分服务共用一条 `CacheInterface` 主线。
- **代价：** 「任意后端任意序列化格式」不在默认可许愿范围内。

## 核心选型 {#core-choice}

对外：**`Psr\SimpleCache\CacheInterface`**；默认实现：**`Switon\Caching\SimpleCache`**。

- **API：** `get` / `set` / `delete` 与批量族；
- **键：** 偏 Redis 的工程可读串；
- **载荷：** JSON 片段；
- **可观测：** 命中 / 未命中 / 写 / 删 / 清事件可监听。

## 取舍 {#trade-off}

坚持用 **JSON** 而非 PHP native serialize。

- **好处：** 可巡检、易于跨运行时共享语义。
- **代价：** 读回后需自建对象。

## 安全边界 {#safety-boundary}

`clear()` 与 CLI **`cache:list`** 都要求 **`prefix` 非空**。

- **好处：** 多租户共用 Redis 时不易一锅端。
- **代价：** 未配置前缀的调用会直接失败而非默默清库。

## 为何 Redis {#why-redis}

在同一条后端上同时搞定连接池、TTL、批量与前缀扫描心智成本最低。

- **好处：** 包体积小、运维手册大家熟。
- **代价：** Redis 兼容性以外的后端需另起集成。
