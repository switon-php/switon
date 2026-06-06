---
sidebar_label: Sync 设计说明
description: 四原语分离、运行时差异可见；Stack 不是 Pool。
---

# 设计说明 {#design-rationale}

`switon/sync` **刻意保持小**：分立的四种原语、显式的运行时差异、不藏一层池化。

## 参见 {#see-also}

- [Sync 包](README.md) — 用法与边界
- [FAQ](faq.md#sync-faq) — 运行时与边界情况
- [Pool 包](../pool/README.md) — 借还生命周期

---

## 要解决的问题 {#core-problem}

应用里常见四种模式：有序交接、互斥、等完成、最新优先复用。混成一个「同步工具类」后，关闭语义、所有权与公平性在 code review 里难以看清。

## 为何四种原语 {#primitives}

- `Channel` — 有序交接；
- `Mutex` — 互斥；
- `WaitGroup` — 完成等待；
- `Stack` — LIFO 复用。

调用点意图可见，每个原语职责窄。

## 运行时差异不伪装 {#runtime-differences-visible}

协程可挂起，普通 PHP 请求不能。`sync` 不把两种运行时伪装成同一套「总会阻塞」行为——不能阻塞时，空读、等待、超时会走不同的失败/返回路径。

## Stack 不是 Pool {#stack-pool}

`Stack` 只是有界 LIFO 容器。借还、owner、资源管理属于 [Pool](../pool/README.md)，避免把简单复用 primitive 膨胀成池管理器。

## 刻意简单 {#deliberate-simplicity}

- 无统一「同步门面」；
- 无隐藏的队列→锁转换；
- 无 `switon.yml` 面；
- 无广播式等待。

代价是便利少一些；收益是协调逻辑在关键路径上保持显式。
