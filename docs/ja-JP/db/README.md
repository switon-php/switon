---
sidebar_label: DB パッケージ
description: switon/db：名前付きバインド、transient、読み書きルーティング、可搬 SQL。
---

# DB パッケージ {#db-package}

`switon/db` は Switon アプリ向けに、名前付きバインド付きの直接 SQL、既定でステートレスなクライアント、ローカルトランザクション用のピン留め transient 接続、任意の読み書きルーティングを提供します。

**主な機能:**

- **直接 SQL 契約:** `Switon\Db\ClientInterface` を注入し、名前付きプレースホルダを使う。
- **既定はステートレス:** 通常の読み書きは呼び出しごとに接続を借りる。
- **ローカルスコープのピン:** `getTransient('default')` でトランザクションや書き込み直後の読み取りを同一接続に固定。
- **読み書きルーティング:** 読み取りはレプリカ、書き込みはマスター経路。
- **可搬 SQL と可観測性:** `[identifier]` クォートとライフサイクルイベント。

---

## インストール {#installation}

**要件:** PHP 8.3 以上、`ext-pdo`、対象 DB の PDO ドライバ

```bash
composer require switon/db
```

---

## クイックスタート {#quick-start}

- データベースをひとつ設定する。
- クライアントを注入する。
- パラメータ化 SQL を使う。

```yaml
# switon.yml
Switon\Db\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: mysql://root:password@localhost/myapp
```

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Db\ClientInterface;

final class UserService
{
    #[Autowired] protected ClientInterface $db;

    public function findById(int $id): ?array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [name] FROM [users] WHERE [id] = :id',
            ['id' => $id],
        );

        return $rows[0] ?? null;
    }
}
```

- ひとつのローカルスコープで同じ接続が要るときは transient をピン留め:

```php
$tx = $this->db->getTransient('default');
$tx->begin();

try {
    $tx->insert('users', ['name' => 'Jane']);
    $tx->commit();
} catch (\Throwable $e) {
    $tx->rollback();
    throw $e;
}
```

- トランザクションと書き込み直後の読み取りには `getTransient('default')`。
- `auto` と `slave` は読み取りルーティングのヒントであり、トランザクションスコープではない。
- 多くのアプリは `default.uri` ひとつと注入クライアントで足りる。

---

## コア概念 {#core-concepts}

### Transient 接続 {#transient-connections}

共有の `ClientInterface` が通常経路。

- トランザクション状態や書き込み直後の一貫性が要るローカルスコープでは `getTransient('default')`。
- レプリカがあれば読み側に `slave` を選びたいときだけ `getTransient('auto')`。
- レプリカ読み取りを強制し、無いときは `default` へのフォールバックを許容するときだけ `getTransient('slave')`。

ORM 主導のスコープでは `Switon\Db\TransactionManagerInterface` がすでにバインドされたクライアントを返し、別スコープを開かない。

### 読み書き経路 {#read-write-paths}

- `fetchAll()` はトポロジにレプリカがあればレプリカから読みうる。
- `insert()`、`update()`、`upsert()`、`delete()` は書き込み経路。
- `executeInsert()` / `executeUpdate()` / `executeDelete()` と `insertBySql()` / `updateBySql()` / `deleteBySql()` が直接 SQL の入口。
- `insert(..., true)` は影響行数ではなく挿入 ID を返す。
- `[identifier]` 記法で手書き SQL をドライバ横断で可搬に保つ。

### 接続トポロジ {#connection-topology}

- まず `default.uri` ひとつ。
- サービスごとに DB が違う、またはひとつの `uri` をマスター+レプリカにするときだけ増やす。

複数 URI の `default.uri` では **先頭がマスター**、残りがレプリカ。

```yaml
# switon.yml
Switon\Db\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: mysql://root:password@master/app,mysql://root:password@replica1/app,mysql://root:password@replica2/app
```

- 読み取りはレプリカ、書き込みは先頭 URI に向く構成向け。

---

## 設定 {#configuration}

URI 文字列の形:

```text
driver://username:password@host:port/database?options
```

例:

```text
mysql://root:password@localhost/myapp
pgsql://postgres:password@localhost/myapp
sqlite:///path/to/database.db
sqlite::memory:
```

`mssql://...` もサポート。

安定したクエリオプションは `uri` 内:

- `pool_size` — プールサイズ。`4/8` でマスター／レプリカを分割
- `timeout` — プール取得タイムアウト
- `prefix` — 高レベル CRUD ヘルパーへのテーブル接頭辞
- `charset`、`readonly`、`emulate_prepares` — アダプタ固有
- レプリカ URI はプール構築時に自動で `readonly=1`

---

## オプションの確認 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Db\ClientInterface'
```

---

## 注意点 {#keep-in-mind}

- `fetchAll()` はレプリカに行きうる。直後の一貫性が要るときは transient をピン留め。
- `update()` と `delete()` は空条件・単語だけの条件を拒否し、誤った全表更新を防ぐ。
- 意図的な no-op 削除には `WHERE FALSE`。
- `allocateIds()` はトランザクション外で実行。
- `config:options` が現在のバインドとオプション面の最短確認。
- `begin()` / `commit()` / `rollback()` は transient クライアントが必要。

---

## 次のステップ {#next}

- **よくある質問:** [FAQ](faq.md)
- **設計の狙い:** [設計の狙い](design.md)
- **テスト:** [テスト](testing.md)
- **内部設計:** [DB 内部](internals/README.md)
