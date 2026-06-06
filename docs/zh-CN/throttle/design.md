---
sidebar_label: Throttle 设计说明
description: 共享 Redis 计数、属性优先；非配额引擎。
---

# 设计说明 {#design-rationale}

`switon/throttle` 把请求防护做小：**共享计数器、显式键、HTTP 与 `hit()` 同一路径**。

## 参见 {#see-also}

- [Throttle 包](README.md) — 用法、配置与规则语法
- [FAQ](faq.md#throttle-faq) — 常见问题

---

## 要解决的问题 {#core-problem}

限速容易变乱：每条路由自建计数、策略不一。

- worker 间计数漂移；
- 限制离开请求边界；
- burst 在临时代码里重复实现；
- 业务配额与基础滥用防护混在一起。

本包先统一常见边界。

## 为何 Redis {#why-redis}

计数须在进程/实例间一致；本地内存回退会在每台机器上给出不同答案。

## 为何属性优先 {#why-attributes-first}

`#[RateLimit]` 让默认 HTTP 路径贴近受保护代码：类级基线、方法级覆盖；应用自有 key 仍用 `hit()`。

## Burst 故意收窄 {#burst-narrow}

只对列表**第一条**规则放宽突发，避免每条规则都变成复杂算法。

## 刻意简单 {#deliberate-simplicity}

Throttle 是**限流器**，不是配额引擎。

- 无按目的地策略树；
- 无内置白名单模型；
- 无自动限速响应头；
- 无产品档位逻辑。

代价是策略面更小；收益是行为可预测、贴近请求边界。
