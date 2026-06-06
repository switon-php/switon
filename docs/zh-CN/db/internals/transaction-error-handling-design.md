---
sidebar_label: 事务错误处理设计
description: ClientInterface 与 TransactionManagerInterface 在嵌套 begin 与 rollback 上的行为差异。
---

# 事务错误处理设计 {#transaction-error-handling-design}

为何 `Switon\Db\ClientInterface` 与 `Switon\Db\TransactionManagerInterface` 在**嵌套 `begin()`** 与**无活动事务时的 `rollback()`** 上表现不同。

## 概览 {#overview}

Switon 有两层事务 API，目标不同：

- **`ClientInterface`** — 偏底层 SQL 操作；
- **`TransactionManagerInterface`** — 偏 ORM 层协调。

目标不同，嵌套与回滚时的错误处理策略也不同。

---

## 行为差异 {#behavior-difference}

| API                                     | 已有活动事务时再 `begin()`                         | 无活动事务时 `rollback()`                         |
|-----------------------------------------|----------------------------------------------------|---------------------------------------------------|
| `Switon\Db\ClientInterface`             | 嵌套层级 +1                                        | **无操作**（静默返回）                            |
| `Switon\Db\TransactionManagerInterface` | 抛出 `CrossDatabaseTransactionException`           | 抛出 `NoActiveTransactionException`               |

两层在**无活动事务时 `commit()`** 都会抛错。

---

## 为何 `ClientInterface` 更宽松 {#clientinterface-lenient}

底层代码常在 `finally` 里做防御性清理：

```php
$tx = $db->getTransient('default');
try {
    $tx->begin();
    // ... SQL ...
    $tx->commit();
} catch (\Throwable $e) {
    $tx->rollback(); // 即便 begin() 失败也安全
    throw $e;
}
```

`rollback()` 静默可避免清理路径上再叠一层错误。

---

## 为何 `TransactionManagerInterface` 更严格 {#transactionmanagerinterface-strict}

ORM 层事务是**协调逻辑**。此层在无活动作用域时 `rollback()` 通常表示编程错误：

- `begin()` / `commit()` / `rollback()` 不成对；
- 对当前连接作用域的假设错误。

**快速失败** 有助于尽早暴露这类 bug。

---

## 行为矩阵 {#behavior-matrix}

| 操作                                   | `ClientInterface`        | `TransactionManagerInterface`                                  |
|----------------------------------------|--------------------------|----------------------------------------------------------------|
| 已有活动事务时再 `begin()`             | 嵌套层级 +1              | 抛出 `CrossDatabaseTransactionException`                       |
| 无活动事务时 `commit()`                | 抛出                     | 抛出                                                           |
| 无活动事务时 `rollback()`              | 无操作                   | 抛出                                                           |

---

## 使用建议 {#usage-guidance}

- 需要**防御性**手写 SQL 事务 → 用 `ClientInterface`（配合瞬连）；
- ORM 级事务协调 → 用 `TransactionManagerInterface`；
- 业务代码优先用回调或属性驱动的事务编排。

---

## 参见 {#see-also}

- [瞬连](../README.md#transient-connections) — 面向用户的事务用法
- [设计说明](../design.md) — DB 层设计原则
