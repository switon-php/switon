---
sidebar_label: トランザクションエラー処理
description: Client と TransactionManager のネスト begin／rollback の違い。
---

# トランザクションエラー処理の設計 {#transaction-error-handling-design}

`Switon\Db\ClientInterface` と `Switon\Db\TransactionManagerInterface` が、ネストした `begin()` と「アクティブなトランザクションが無いときの `rollback()`」でなぜ違うか。

## 概要 {#overview}

Switon には目的の違う二つのトランザクション層がある:

- 低レベル SQL 用の `ClientInterface`
- ORM 調整用の `TransactionManagerInterface`

目的が違うため、ネストと rollback のエラー処理も違う。

---

## 挙動の違い {#behavior-difference}

| API                                     | 既にアクティブなときの `begin()`                               | アクティブなしの `rollback()`                     |
|-----------------------------------------|----------------------------------------------------------------|---------------------------------------------------|
| `Switon\Db\ClientInterface`             | ネストレベルを増やす                                           | 何もしない（黙って return）                       |
| `Switon\Db\TransactionManagerInterface` | `CrossDatabaseTransactionException` を投げる                   | `NoActiveTransactionException` を投げる           |

アクティブなしの `commit()` は両方で例外。

---

## なぜ `ClientInterface` は寛容か {#clientinterface-lenient}

低レベルコードは防御的なクリーンアップをよく使う:

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

`begin()` が失敗しても `rollback()` が黙って通ると、クリーンアップ経路で二次エラーを避けられる。

---

## なぜ `TransactionManagerInterface` は厳格か {#transactionmanagerinterface-strict}

ORM 層のトランザクションは調整ロジック。  
ここでアクティブスコープ無しの `rollback()` は多くの場合プログラミングエラー:

- `begin()` / `commit()` / `rollback()` の不均衡
- アクティブ接続スコープの誤った前提

早期失敗でバグを表に出しやすくする。

---

## 挙動マトリクス {#behavior-matrix}

| 操作                                    | `ClientInterface`        | `TransactionManagerInterface`                                  |
|-----------------------------------------|--------------------------|----------------------------------------------------------------|
| アクティブ中の `begin()`                | ネストレベル増加         | `CrossDatabaseTransactionException`                            |
| アクティブなしの `commit()`             | 例外                     | 例外                                                           |
| アクティブなしの `rollback()`           | 何もしない               | 例外                                                           |

---

## 利用の指針 {#usage-guidance}

- 防御的な手動 SQL トランザクションには `ClientInterface`。
- ORM 層の調整には `TransactionManagerInterface`。
- 業務コードではコールバックや属性によるトランザクション編成を優先。

---

## 参照 {#see-also}

- [Transient 接続](../README.md#transient-connections) — 利用者向けトランザクション
- [設計の狙い](../design.md) — DB 層の原則
