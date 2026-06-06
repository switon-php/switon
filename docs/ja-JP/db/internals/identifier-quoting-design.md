---
sidebar_label: 識別子クォート設計
description: identifier 角括弧記法とドライバ別 convertQuotes。
---

# 識別子クォートの設計 {#identifier-quoting-design}

`switon/db` が MySQL、PostgreSQL、SQLite、SQL Server で識別子クォートを可搬に保つ方法。

## 概要 {#overview}

Switon DB は内部 SQL 記法として `[identifier]` を使う。  
各アダプタが実行時にネイティブのクォートスタイルへ変換する。

ひとつの SQL 文字列を複数バックエンドで動かせる。

---

## 基本ルール {#core-rules}

1. 識別子は角括弧で書く（例: `[users]`、`[created_at]`）。
2. クライアント／接続内部はクエリ実行まで角括弧構文を保持。
3. アダプタの `convertQuotes()` がドライバごとに角括弧を変換。
4. バッククォート／二重引用符と角括弧構文を手で混ぜない。

---

## なぜ角括弧か {#why-brackets}

### 可搬性 {#portability}

| ドライバ   | アダプタ出力   | 角括弧の扱い           |
|------------|----------------|------------------------|
| MySQL      | `` `name` ``   | `[name]` から変換      |
| SQLite     | `` `name` ``   | 現行アダプタで `[name]` から変換 |
| PostgreSQL | `"name"`       | `[name]` から変換      |
| SQL Server | `[name]`       | 変換不要               |

### PHP 内の SQL テキスト {#simpler-text-php}

角括弧は PHP 文字列のエスケープを単純にし、クロスドライバ SQL を読みやすく保つ。

---

## 変換フロー {#conversion-flow}

1. クライアント生成 SQL は角括弧記法。
2. `AbstractConnection::execute()` / `query()` がアダプタ `convertQuotes()` を呼ぶ。
3. ドライバ固有 SQL が PDO に送られる。

---

## `escapeIdentifier()` のルール {#escapeidentifier-rules}

`escapeIdentifier()` は **角括弧記法だけ** を返す。  
MySQL／PostgreSQL 固有のクォートを直接出力しない。

例:

| 入力             | 出力               |
|------------------|--------------------|
| `users`          | `[users]`          |
| `schema.users`   | `[schema].[users]` |
| `[users]`        | `[users]`          |
| `[schema].users` | `[schema].[users]` |

---

## ベストプラクティス {#best-practices}

1. DB パッケージ API 向けの手書き SQL では角括弧記法を使う。
2. 高レベル API（`insert`、`update`、`getTables`）にはプレーンなスキーマ／テーブル名を渡し、クォートは任せる。
3. `[[name]]` や手動の混在クォートは避ける。

---

## 参照 {#see-also}

- [DB パッケージ](../README.md#read-write-paths) — クロス DB の使い方
- [設計の狙い](../design.md) — DB 全体の設計判断
