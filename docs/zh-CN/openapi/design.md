---
sidebar_label: OpenAPI 设计说明
description: 拆分 YAML + 保守 sync；operationId 对齐 handler id。
---

# 设计说明 {#design-rationale}

`switon/openapi` 在保留手写 YAML 的同时，让 API 文档与**控制器路由**对齐。

## 参见 {#see-also}

- [OpenAPI 包](README.md) — 搭建、工作流与命令
- [FAQ](faq.md#openapi-faq) — 常见问题

---

## 为何存在 {#why-this-exists}

映射、`operationId` 与 YAML 各走各的规则时，文档必然漂移。本包标准化**与路由同源**的一条路径，使文档贴近拥有该路由的代码。

## 主要取舍 {#main-trade-offs}

- 拆分文件比单一大 spec 更易维护；
- sync **保守**，不替你重写已有 YAML；
- `operationId` 对齐 handler id，命名自由度略小，工具链更一致；
- export 可解引用本地 ref，但不充当完整外部 bundler。

## 刻意限制 {#deliberate-limits}

- sync **只追加**缺失 stub；
- `--deref` **仅**本地 `#/...`；
- lint 对弱结构发警告，仍保持轻量编写循环。
