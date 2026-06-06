---
sidebar_label: Renderer FAQ
description: Renderer：Frames 复用、主题仅 /View/、sword:compile 与 partial。
---

# Renderer FAQ {#renderer-faq}

**Frames、主题查找与 Sword 相关的短答。**

> **阅读前提：** [Renderer 包](README.md)

## 入门 {#getting-started}

### 为什么 `render()` 返回 Frames？ {#why-frames}

一次调用除输出外还携带 section、stack、theme、once 等状态。亦可用 `exists()` 检查模板是否存在。

### 何时复用同一 Frames？ {#reuse-frames}

多次执行须共享状态时复用；每次应隔离则用新实例。

### 能混用 .phtml 与 .sword？ {#mixed-engines}

可以；按**解析到的文件扩展名**选引擎。

## 模板 {#templates}

### `$__frames` 做什么？ {#frames-runtime}

提供当前渲染事务与组合辅助方法。

### 为何保留 `__frames`？ {#reserved-frames}

由 Renderer 自动注入；用户数据勿用同名键。`EXTR_SKIP` 下冲突键会被跳过。

### 主题查找适用于所有模板？ {#theme-scope}

**否。** 仅对解析路径中含 **`/View/`** 的模板生效。

## Sword {#sword}

### 何时跑 `sword:compile`？ {#compile-command}

希望在流量到来前预热编译产物时。

### Sword 会一直用缓存编译结果吗？ {#sword-recompile}

不会；输出缺失、源文件更新或 debug 模式时会重新编译。`sword:compile` 会先清空运行时 Sword 缓存目录。

### `partial()` 失败？ {#partial-fail}

须在**活跃**渲染事务内调用；顶层 `render()` 开始前不可用。
