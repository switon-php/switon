---
sidebar_label: Storage 设计说明
description: 小而明确的本地 blob 边界；列举与远程策略在应用层。
---

# 设计说明 {#design-rationale}

`switon/storage` 只划定**一块本地 blob 边界**，文件列举与远程存储策略留给应用。

## 参见 {#see-also}

- [Storage 包](README.md) — 用法、配置与边界
- [FAQ](faq.md#storage-faq) — 常见问题

---

## 要解决的问题 {#core-problem}

路径规则、命名、目录列举与「当成云存储」混在一起时，文件处理会漂移且难审计。

## 首要目标 {#primary-goal}

标准化 **blob 读写**，而不是文件管理策略：

- 本地字节易存易读；
- 元数据在 blob 之外；
- 归属与列举由应用显式负责。

## 取舍 {#trade-off}

- **好处：** 存储相关代码保持精简。
- **好处：** 键可移植且受根目录约束。
- **代价：** 文件列举、搜索与远程存储在包外处理。

## 刻意限制 {#deliberate-limits}

- 按键寻址的存储，非浏览型文件系统。
- 首选本地文件系统。
- 同时支持字符串与流访问。
- `putFile()` 为辅助方法，不是文件系统策略层。
