---
sidebar_label: Validation FAQ
description: Validation：入口、规范化、Required 与 HTTP 自动必填。
---

# Validation FAQ {#validation-faq}

**校验与消息相关的短答。**

> **阅读前提：** [Validation 包](README.md)

## 入门 {#getting-started}

### 校验失败会怎样？ {#validation-fails}

抛 `ValidateFailedException`，携带字段 → 错误信息 map。

### 返回值会规范化吗？ {#normalized-values}

会。`Trim`、`Defaults`、`Type`、`Email`、`Date`、大小写转换等都可能改变返回值。

### 用哪个入口？ {#entrypoint}

数组或对象的渐进字段校验 → `validate($source, $constraints)`（同形返回）；手动会话 → `beginValidate()` / `endValidate()`。失败抛 `ValidateFailedException`，**不是**返回 `null`。

## 约束 {#constraints}

### 如何必填？ {#required}

用 `Required`。HTTP 类型化绑定里，无默认值的 **non-nullable** public 属性也会被当作必填。

### 「可选，但有值必须是 int」？ {#optional-typed}

手动校验时不要指望单独 `Type('int')` 让缺失字段变可选；先判存在或用可空/带默认的入参形状。

### 一条约束既规范化又校验？ {#normalize-and-validate}

可以，如 `Email`、`Date`、`Defaults`、`Trim` 等。

### 枚举与常量白名单？ {#enum-and-constants}

`EnumValue` / `ConstantValue`。

### 能校验对象源？ {#object-source}

`beginValidate()` 接受数组与对象；对象源可影响基于源类的标签。

## 消息 {#messages}

### 如何改展示字段名？ {#field-labels}

约束 label、模板 label 或 i18n 键——只改展示文案，不改错误 map 的键。

### 自定义消息为何当字面量？ {#literal-message}

不含 `\` 的字符串视为字面模板；要查 locale 文件请用含 `\` 的键。

### 如何查看配置项？ {#discover-options}

`bash bin/console config:options 'Switon\Validating\ValidatorInterface'`

### 有哪些内置约束？ {#built-in-constraints}

含 `ArrayOf` 及存在性、类型、字符串、数值、集合、边界、规范化等（见 README 分组列表）。

## 排错 {#troubleshooting}

### 为何输出里没有某缺失字段？ {#missing-output}

缺失字段保持缺失，除非 `Defaults` 等写入。

### 没写 `#[Required]` 为何仍必填？ {#auto-required}

[switon/http](../http/typed-input-binding.md) 对无默认的 non-nullable 属性按必填处理。

### 嵌套对象/对象数组？ {#nested-validation}

`ArrayOf`；嵌套失败路径用点号连接。

### 还有哪些异常？ {#other-exceptions}

视路径可能出现 `LocaleTemplateNotFoundException`、`InvalidConstraintTargetException`、`InvalidConstraintSourceException`、`UnsupportedValidationTypeException`、`ConstraintViolationException` 等。
