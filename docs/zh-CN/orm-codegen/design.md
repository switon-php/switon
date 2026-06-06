---
sidebar_label: ORM Codegen 设计说明
description: 先预览再写入、映射优先、文件级模板定制。
---

# 设计说明 {#design-rationale}

`switon/orm-codegen` 保持**基于文件**的生成，并在写入前提供预览通道。

## 参见 {#see-also}

- [ORM Codegen 包](README.md) — 命令、路径与工作流
- [FAQ](faq.md#orm-codegen-faq) — 常见问题

---

## 先检视再写入 {#inspect-before-writing}

项目若已有实体↔表映射，生成文件极易漂移。因此分离「当前映射」与「下一次输出」：

- `entity:list` — 当前映射；
- `entity:spec` — 将生成的类名、主键、属性；
- `entity:make` — 在预览之后才真正写文件。

便于首轮 review，也避免脚本/CLI 过早猜测。

## 映射优先复用 {#reuse-mapping-first}

已有实体映射是最强信号：表已有关联类则**复用**命名空间与类名，而非另起新名——在应用已拥有部分模型布局时，重新生成更安全。

代价是**扫描路径**必须覆盖实体实际所在目录。

## 输出位置可预期 {#keep-output-local}

默认 `auto` 在「首次便利」与「长期安全」间折中：

- 新应用可写入 `@app`；
- 已有实体树可落到 `@runtime/orm`；
- 显式 `app` / `runtime` 去掉猜测。

目标是**可预测放置**，而非对所有项目一刀切。

## 定制留在文件层 {#customization-file-based}

模板覆盖刻意保持简单：显式路径优先 → 应用级 `Entity.sword` / `Repository.sword` → 内置模板回退。长期变更靠近应用，而不是膨胀成大型扩展 API。
