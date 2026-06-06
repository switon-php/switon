---
sidebar_label: 标识符引用设计
description: switon/db 如何用 [identifier] 在 MySQL/PG/SQLite/SQL Server 间保持可移植引用。
---

# 标识符引用设计 {#identifier-quoting-design}

`switon/db` 如何在 MySQL、PostgreSQL、SQLite、SQL Server 之间保持标识符引用的可移植性。

## 概览 {#overview}

Switon DB 在内部 SQL 记号中使用 **`[identifier]`**。  
各适配器在执行前把它转成该驱动的原生引号风格。

这样**同一条 SQL 字符串**可在多种后端上运行。

---

## 核心规则 {#core-rules}

1. 标识符用方括号书写，例如 `[users]`、`[created_at]`。
2. Client/Connection 内部在真正执行查询前仍保持方括号语法。
3. 适配器的 `convertQuotes()` 按驱动做转换。
4. 不要手写反引号/双引号再与方括号语法混用。

---

## 为何用方括号 {#why-brackets}

### 可移植性 {#portability}

| 驱动       | 适配器输出   | 方括号处理                         |
|------------|--------------|------------------------------------|
| MySQL      | `` `name` `` | 由 `[name]` 转换                   |
| SQLite     | `` `name` `` | 当前适配器由 `[name]` 转换         |
| PostgreSQL | `"name"`     | 由 `[name]` 转换                   |
| SQL Server | `[name]`     | 无需转换                           |

### PHP 里更简单的 SQL 文本 {#simpler-text-php}

方括号减轻 PHP 字符串里的转义负担，跨驱动 SQL 也更易读。

---

## 转换流程 {#conversion-flow}

1. Client 生成的 SQL 使用方括号记号；
2. `AbstractConnection::execute()` / `query()` 调用适配器 `convertQuotes()`；
3. 驱动专属 SQL 交给 PDO 执行。

---

## `escapeIdentifier()` 规则 {#escapeidentifier-rules}

`escapeIdentifier()` **只返回方括号形式**，不直接输出 MySQL/PostgreSQL 风格的引号。

示例：

| 输入             | 输出               |
|------------------|--------------------|
| `users`          | `[users]`          |
| `schema.users`   | `[schema].[users]` |
| `[users]`        | `[users]`          |
| `[schema].users` | `[schema].[users]` |

---

## 最佳实践 {#best-practices}

1. 为 DB 包 API 手写 SQL 时，统一用方括号记号；
2. 高层 API（`insert`、`update`、`getTables` 等）传**纯** schema/表名，由包处理引用；
3. 避免 `[[name]]` 或手动混用多种引号风格。

---

## 参见 {#see-also}

- [DB 包 — 读写路径](../README.md#read-write-paths) — 跨库日常用法
- [设计说明](../design.md) — DB 层更上层的取舍
