---
sidebar_label: Pool 设计说明
description: (owner,type) 键、guard 优先与运行时 add 播种。
---

# 设计说明 {#design-rationale}

`switon/pool` 为可复用资源标准化**借用/归还**边界。

## 参见 {#see-also}

- [Pool 包](README.md) — 用法、概念与边界
- [FAQ](faq.md#pool-faq) — 短答与排错

---

## 要解决的问题 {#core-problem}

复用说起来简单，做起来容易错。

- 所有权漂到全局名；
- 借还代码漏进各异常路径；
- 应用代码重复生命周期规则；
- 可观测性混进业务逻辑。

## Owner 与 Type {#owner-and-type}

池以 `(owner, type)` 为键。

这使不同 owner 相互隔离，并允许同一 owner 在需要时保留多种池变体。池存在 `WeakMap` 中，池生命周期与 owner 绑定，已退役的 owner 不会泄漏池。

## Guard 优先生命周期 {#guard-first-lifecycle}

`guard()` 为常规借用路径；`acquire()` / `release()` 仍可用于显式生命周期控制。

常见路径保持简短，同时在借出资源须跨越更长生命周期时仍支持手动交接。

## 运行时播种 {#runtime-seeding}

`add()` 可在池尚不存在时创建池，便于在服务构造或初始化路径中播种，引导代码更简单。

## 可观测性 {#observability}

借用、归还、忙碌与归还失败等事件将生命周期可见性留在应用逻辑之外。
