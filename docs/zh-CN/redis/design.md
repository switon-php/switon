---
sidebar_label: Redis 设计说明
description: 池化优先 + 有状态命令显式 getTransient；URI 路由四类拓扑。
---

# 设计说明 {#design-rationale}

`switon/redis` 标准化池化访问，同时让**绑定连接**的工作流保持显式。

## 参见 {#see-also}

- [Redis 包](README.md) — 池化/Transient、URI、诊断
- [Redis FAQ](faq.md#redis-faq) — 常见用法短答

---

## 为何存在 {#purpose}

同一应用里 Redis 承担缓存、会话、队列、计数、协调等角色，**连接状态需求并不相同**。本包提供统一模型：池化普通命令、状态敏感时显式亲和、URI 驱动单节点/TLS/Sentinel/Cluster、生命周期事件可观测。

## 池化路径优先 {#pooled-path-comes}

多数调用短且无状态；池化路径简单高效。代价是有状态命令必须 **`getTransient()`** 显式选用。

## 为何有 Guard {#guard}

依赖连接连续性的命令在池化客户端上**快速失败**，比默默透传更安全，避免隐藏连接状态污染常见路径。

## 刻意不拥有 {#outside}

不隐藏阻塞命令语义；不把 Redis 变成缓存抽象；多 Redis 目标时**不猜测**该用哪个命名客户端——由应用配置决定。
