---
sidebar_label: DB FAQ
description: DB の導入、transient、レプリカ、トランザクション、条件の安全制約。
---

# DB FAQ {#db-faq}

**よくある質問とトラブルシュート。**

> **前提:** [DB パッケージ](README.md)

## はじめに {#getting-started}

### 多くのアプリの出発点は？ {#default-start}

`default.uri` ひとつ、注入した `ClientInterface`、名前付きバインド付きの prepared SQL。
[クイックスタート](README.md#quick-start) を参照。

### いつ `getTransient('default')`？ {#transient-client}

トランザクションや書き込み直後の読み取りで **同一接続** を保つローカルスコープのとき。
[Transient 接続](README.md#transient-connections) を参照。

### いつ `getTransient('auto')` や `slave`？ {#transient-read-routing}

読み取りルーティング専用。`auto` はレプリカがあれば選ぶ。`slave` は可能ならレプリカ経路、無ければ `default` にフォールバック。
[Transient 接続](README.md#transient-connections) を参照。

### いつ `TransactionManagerInterface`？ {#transaction-manager}

生 SQL を ORM 主導のトランザクションに参加させるとき。すでにバインドされたクライアントを返す。
[Transient 接続](README.md#transient-connections) を参照。

## 設定 {#configuration}

### 複数データベースの設定は？ {#multiple-connections}

`Switon\Db\ClientInterface` 下に名前付き `Switon\Di\Factory` エントリを置き、プロパティ名に合わせて注入。

### 書き込み直後に読み取りが古い {#stale-read}

`fetchAll()` がレプリカに行くため。次の読み取りが書き込みを見る必要があるときは `getTransient('default')` でスコープをピン留め。

### 共有クライアントで `begin()` が失敗 {#begin-transient}

トランザクション API は transient 向け。先に `getTransient('default')` を呼んでから `begin()`。

### 接頭辞（prefix）は？ {#prefixes}

URI に `prefix=app_` を設定。高レベル CRUD ヘルパーは自動適用。手書き SQL は手動のまま。

### `update()` / `delete()` が条件を拒否 {#unsafe-condition}

空条件と単語だけの条件は拒否される。実際の SQL 式を使うか、意図的な no-op 削除なら `WHERE FALSE`。

### オプション面の確認は？ {#inspect-options}

`bash bin/console config:options 'Switon\Db\ClientInterface'` — 具象クラスとトップレベル注入プロパティが見える。

### 読み取りレプリカの配線は？ {#wire-replicas}

複数 URI の `default.uri`。先頭がマスター、残りがレプリカ。レプリカ接続は自動で `readonly=1`。
[接続トポロジ](README.md#connection-topology) を参照。

## トラブルシュート {#troubleshooting}

### なぜ条件が拒否される？ {#unsafe-operation}

意図しない全表更新を防ぐため。広い操作が意図的なら `updateBySql()` や `deleteBySql()` を使う。

### 接続失敗時の最初の確認 {#connection-errors}

`bash bin/console db:info` または `db:ping`、ホスト・認証・プールサイズ・長寿命 transient を確認。
