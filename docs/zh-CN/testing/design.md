---
sidebar_label: Testing 设计说明
description: 介于 PHPUnit 与完整 app boot 之间的包测标准层。
---

# 设计说明 {#design-rationale}

`switon/testing` 让包测试**贴近运行时接线**，又不必启动完整应用。

## 参见 {#see-also}

- [Testing 包](README.md) — 搭建、runner 与默认绑定
- [FAQ](faq.md#testing-faq) — 常见问题
- [数据库测试](../db/testing.md#testing-database-code) — 数据库相关模式

---

## 为何存在 {#why-it-exists}

包测往往比裸 PHPUnit fixture 多一步，又比全量 boot 轻。本包统一这层「中间地带」：

- 像运行时的容器；
- 不掩盖已派发事件；
- 全仓库统一的 `tests/run.sh` 入口。

## 刻意负责 {#owns}

`Switon\Testing\Container\Container` 管默认测试接线：

- 行为重要的保留真实现；
- 需要确定性的用 double；
- Provider 只 `register()`，不 `boot()`。

## 刻意不负责 {#outside}

- 不取代包内自有 TestCase/helper（需要时仍可薄封装）；
- 不鼓励断言私有状态；
- 不提供浏览器/E2E/快照测试。

## 事件边界 {#event-boundary}

测试常要确认「有没有派发」，却不总要监听器副作用。收集与执行故意分离；更紧时用 `MockEventDispatcher`。

## Runner 边界 {#runner-boundary}

`tests/run.sh` 让 monorepo 与独立包文档共用一套命令面，避免同一包教两种 PHPUnit  invocation。
