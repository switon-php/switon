---
sidebar_label: YAML 设计说明
description: 可审子集 + 显式插值；非通用 YAML 引擎。
---

# 设计说明 {#design-rationale}

`switon/yaml` **刻意收窄**：一种可 review 的子集、显式插值、单一解析异常类型。

## 参见 {#see-also}

- [YAML 包](README.md) — 用法、子集与占位符
- [FAQ](faq.md#yaml-faq) — 适用场景与不支持的语法

---

## 要解决的问题 {#core-problem}

若接受的 YAML 形态过宽，配置类 YAML 难以信任：

- anchor 与 alias 使文档更难推理；
- tag 与 merge key 增加审阅时易忽略的行为；
- 解析与替换混为一体时，占位符语义不清。

本包将常见形态保持得小而可预测。

## 为何严格子集 {#strict-subset}

`switon/yaml` **不是**完整 YAML 引擎。它标准化 Switon 最常用的部分：

- 映射；
- 序列；
- 常见标量；
- 块标量；
- 简单占位符解析。

代价是 YAML 兼容性更少；收益是更易审阅、也更易替换实现。

## 为何解析与插值分离 {#parsing-interpolation-separate}

解析只回答一个问题：文档描述了什么值树？

插值回答另一个问题：哪些已解析字符串需要填入运行时值？

两个阶段分离后，测试与说明都更简单。

## 为何专用失败类型 {#failures-specific}

单一 `YamlParseException` 覆盖子集违规与占位符错误，避免再引入第二种 YAML 错误风格。

## 刻意边界 {#deliberate-boundary}

不提供 anchor/alias、tag、merge key、flow mapping——便于在组件与 `switon.yml` 路径上统一「何为合法配置」。
