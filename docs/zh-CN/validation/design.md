---
sidebar_label: Validation 设计说明
description: 统一 Validator、小约束、标签与错误键分离。
---

# 设计说明 {#design-rationale}

`switon/validation` 把校验贴在**字段契约**上：一个共享 `ValidatorInterface`、小约束、清晰的消息塑形。

## 参见 {#see-also}

- [Validation 包](README.md) — 用法、配置与公开契约
- [FAQ](faq.md#validation-faq) — 常见问题

---

## 要解决的问题 {#core-problem}

校验很容易漂移：每个输入源自造规则与文案风格。

- 有的路径先 trim，有的校验原始文本；
- 有的返回规范化值，有的不返回；
- 有的用原始字段名，有的用本地标签；
- 数据来源选择与字段规则混在一起。

本包先统一**字段**边界。

## 为何一个 Validator {#why-one-validator}

服务代码、类型化绑定等共用同一接口，语义一致；代价是不会变成「输入规划器」，只管字段规则。

## 为何约束可规范化 {#constraints-normalize}

规范化是规则契约的一部分，而不是单独 pre-pass；**顺序**因此重要。

## 为何消息键用真实符号 {#keys-real-symbols}

`AbstractConstraint` 默认用 `static::class` 作 locale 键，与代码符号对齐，避免第二套别名表；一次性句子仍可用字面字符串。

## 为何标签与错误键分离 {#separate-error-keys}

人看的文案与机器用的字段路径分开：标签可 i18n，错误 map 仍用稳定路径（含嵌套点号）。

## 刻意边界 {#deliberate-boundary}

- **http** — body/query/合并输入从哪来；
- **validation** — 字段规则、规范化值、错误文案；
- 类型化绑定是**消费者**，不是所有者。

便于在 HTTP 之外复用本包。
