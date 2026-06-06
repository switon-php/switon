---
sidebar_label: YAML FAQ
description: YAML：子集范围、占位符类型、与完整 YAML 库选型。
---

# YAML FAQ {#yaml-faq}

**Switon YAML 子集相关的短答。**

> **阅读前提：** [YAML 包](README.md)

## 入门 {#getting-started}

### 用本包还是完整 YAML 库？ {#full-yaml-library}

要**刻意**使用 Switon 子集时用本包；需要 anchor、tag、任意 flow 等 → **不适合**。

### 必须用 DI？ {#need-di}

不必。框架内注入 `YamlReaderInterface`；bootstrap 边缘可 `new YamlReader()`。

### 子集规范在哪？ {#subset-source}

`packages/yaml/subset.md`（规范源文件）。

## 解析与插值 {#parsing-and-interpolation}

### 为何分 `parse` 与 `interpolate`？ {#why-two-steps}

语法树与运行时占位符是不同边界，分开更易测、更易讲清。

### 占位符值类型？ {#placeholder-types}

`string`、`int`、`float`、`bool`；名称字母开头，仅 `[A-Za-z0-9_]`。

### 引号字符串里会插值吗？ {#placeholder-strings}

会，对**解析后**的字符串插值；空字符串在有 default 时按缺失处理。

## 不支持的能力 {#unsupported-features}

### 为何无 anchor/alias/flow mapping？ {#unsupported-yaml-features}

先标准化**窄而可审**的配置形状。

### 只给 `switon.yml` 用？ {#only-switon-yml}

否，任何适合该子集的配置/数据文件均可。

## 错误 {#security-and-errors}

### 解析失败抛什么？ {#parse-error}

`YamlParseException`。

### 重复键或空文档？ {#duplicates-and-empty}

重复键拒绝；空或仅注释 → `[]`。
