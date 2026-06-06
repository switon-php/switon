# Transaction Error Handling Design {#transaction-error-handling-design}

Why `Switon\Db\ClientInterface` and `Switon\Db\TransactionManagerInterface` behave differently for nested `begin()`
calls and missing active transactions.

## Overview {#overview}

Switon has two transaction layers with different goals:

- `ClientInterface` for low-level SQL operations
- `TransactionManagerInterface` for ORM-level coordination

Because goals differ, nesting and rollback error handling also differ.

---

## Behavior Difference {#behavior-difference}

| API                                     | `begin()` while active                                         | `rollback()` with no active transaction                   |
|-----------------------------------------|----------------------------------------------------------------|-----------------------------------------------------------|
| `Switon\Db\ClientInterface`             | Increments nesting level                                       | No-op (silent return)                                     |
| `Switon\Db\TransactionManagerInterface` | Throws `Switon\Db\Exception\CrossDatabaseTransactionException` | Throws `Switon\Db\Exception\NoActiveTransactionException` |

Commit without an active transaction throws at both layers.

---

## Why `ClientInterface` Is Lenient {#clientinterface-lenient}

Low-level code often uses defensive cleanup:

```php
$tx = $db->getTransient('default');
try {
    $tx->begin();
    // ... SQL work ...
    $tx->commit();
} catch (\Throwable $e) {
    $tx->rollback(); // Safe even if begin() failed
    throw $e;
}
```

A silent rollback avoids secondary errors in cleanup paths.

---

## Why `TransactionManagerInterface` Is Strict {#transactionmanagerinterface-strict}

ORM-level transactions are coordination logic.
At this layer, rollback without an active scope usually indicates a programming error:

- unbalanced `begin()` / `commit()` / `rollback()`
- wrong assumptions about active connection scope

Fail-fast behavior helps surface these bugs early.

---

## Behavior Matrix {#behavior-matrix}

| Operation                               | `ClientInterface`        | `TransactionManagerInterface`                                  |
|-----------------------------------------|--------------------------|----------------------------------------------------------------|
| `begin()` while active                  | Increments nesting level | Throws `Switon\Db\Exception\CrossDatabaseTransactionException` |
| `commit()` without active transaction   | Throws                   | Throws                                                         |
| `rollback()` without active transaction | No-op                    | Throws                                                         |

---

## Usage Guidance {#usage-guidance}

- Use `ClientInterface` when you need defensive manual SQL transactions.
- Use `TransactionManagerInterface` for ORM-level transaction coordination.
- Prefer callback or attribute transaction orchestration in business code.

---

## See also {#see-also}

- [Transient Connections](../README.md#transient-connections) — user-facing transaction usage
- [Design Rationale](../design.md#design-rationale) — DB layer design principles
