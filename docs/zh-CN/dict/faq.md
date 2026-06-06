---
sidebar_label: Dict FAQ
description: Dict：边界、reader、缺省值与 typeCode。
---

# Dict FAQ {#dict-faq}

面向适用性、接线与行为的短答。

> **前置：** [Dict 包](README.md#dict-package)

## 适用性 {#fit}

### `dict` 是 schema 包吗？ {#schema-package}

不是。它规范的是类型化用法与读契约，不强制固定表布局。

### `dict` 只做校验吗？ {#only-validation}

不是。校验是一条路径；同一 dict 类型还驱动动作参数与 UI 选项。

### 和 `enum` 一样吗？ {#same-as-enum}

不一样。`enum` 适合代码拥有的封闭集合；`dict` 适合运行时受控、仍要类型化边界的取值集。

## 接线 {#setup}

### 必须绑 reader 吗？ {#bind-reader}

要。把 `Switon\Dict\DictReaderInterface` 绑到应用 reader。只有 UI 还要选项行时才绑 `Switon\Dict\OptionReaderInterface`。

### 怎么看当前选项面？ {#inspect-options}

`bash bin/console config:options 'Switon\Dict\DictReaderInterface'`。

## 行为 {#behavior}

### 值缺失时怎样？ {#missing-value}

不可空的动作参数：有默认项则回退默认项。可空参数可为 `null`。`#[DictValue]` 属性不会在空输入时自动选默认项；可空时空输入仍为 `null`。

### `typeCode()` 为空会怎样？ {#empty-type-code}

解析失败。重写 `typeCode()` 或使用能推出编码的类名。

### 同一 reader 能既管 dict 又管选项吗？ {#same-reader}

可以。同一数据源同时负责查找与选项行是常见模式。

### `getItem()` 按什么匹配？ {#item-match}

按存储值 `itemValue`，不是 `itemCode`。
