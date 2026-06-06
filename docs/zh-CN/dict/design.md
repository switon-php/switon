---
sidebar_label: Dict 设计说明
description: 为何统一动作参数、DictValue 与 OptionReader，而不规定单一 schema。
---

# 设计说明 {#design-rationale}

`Dict` 针对 Switon 里反复出现的一个问题：**受控取值集**在动作参数、校验与 UI 选项查询之间各写各的。

## 参见 {#see-also}

- [Dict 包](README.md#dict-package) —— 用法、配置与对外边界
- [FAQ](faq.md#dict-faq) —— 适用性、边界与常见问题

---

## 为何要有这一层 {#why-this-exists}

应用通常已经知道怎么存受控值；不一致的是**怎么用**：

- 有的控制器收裸标量；
- 有的表单本地维护 options 数组；
- 有的输入对象用另一套归一化；
- 有的 reader 用自定义 helper 暴露文案。

`Dict` 先收敛这种漂移。

## Switon 规范了什么 {#what-switon-standardizes}

包让这些入口收敛到同一形状：

- 经 `DictResolver` 的类型化动作参数；
- 经 `#[DictValue]` 的标量字段校验与归一化；
- 经 `OptionReaderInterface` 的 UI 选项查询；
- 单一的 `builtin` 查找开关，让同一 reader 同时服务框架内置与应用自有 dict 数据而不必改类型名。

底层数据源可以换，使用形态保持稳定。

## 留在外面的 {#what-stays-outside}

`Dict` **不**规定唯一 schema、管理后台或内置业务内容。

那些留在应用或适配层；框架只规范**读侧契约**以及包裹它的类型化边界。
