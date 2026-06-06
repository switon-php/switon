---
sidebar_label: DB 内部
description: 識別子クォートとトランザクションエラー処理の内部設計ノート。
---

# DB 内部 {#db-internals}

README と `design.md` では足りないとき、クロスドライバの識別子クォートとトランザクションエラー処理の設計を辿る。

## いつ読むか {#scope}

次のときに読む:

- クロスドライバの SQL 挙動を追う
- トランザクション状態や rollback の期待をデバッグする
- 初回導入ではなくパッケージ内部をレビューする

---

## 利用可能なノート {#available-notes}

- [識別子クォートの設計](identifier-quoting-design.md) — 手書き SQL の `[identifier]` とアダプタごとの変換
- [トランザクションエラー処理の設計](transaction-error-handling-design.md) — `ClientInterface` と `TransactionManagerInterface` の rollback の違い

---

## 次のステップ {#next}

通常の利用経路に戻る:

- [DB パッケージ](../README.md)
- [設計の狙い](../design.md)
- [FAQ](../faq.md)
