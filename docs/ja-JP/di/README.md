---
sidebar_label: DI パッケージ
description: switon/di：#[Autowired]、共有/新規、Factory、NamedLookup。
---

# DI パッケージ {#di-package}

`switon/di` は Switon アプリ向けに、インターフェース優先の自動配線、名前付きスロット、共有と新規の明確な分離を伴う **プロパティベース DI** を提供します。

**主な機能:**

- **PSR 向け既定:** `Psr\Container\ContainerInterface` がそのまま使える。
- **インターフェース優先:** 同一名前空間の `XxxInterface` → `Xxx` を自動解決。
- **プロパティ注入:** `#[Autowired]` でサービス・スカラー・配列・`Lazy`。
- **共有と新規:** `get()` は共有サービス、`make()` は新しいオブジェクト。
- **名前付きスロット:** `Factory` でひとつの契約に複数インスタンス。
- **エイリアスリダイレクト:** `#[Autowired]` が `Type#name` スロットへ `#name` や完全なサービス ID で向ける。
- **ランタイム参照:** 名前がデータから来るときは `NamedLookupInterface`。

---

## インストール {#installation}

**要件:** PHP 8.3 以上、`ext-json`

```bash
composer require switon/di
```

---

## クイックスタート {#quick-start}

- 型付き `#[Autowired]` プロパティを宣言する。
- コンテナにオブジェクトを作らせる。

以下の例は `Switon\...` や PSR 名前空間が出てくる場合を除き、説明用の `App\...` 名です。

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;

interface CacheInterface {}
class Cache implements CacheInterface {}

class ProductService
{
    #[Autowired] protected CacheInterface $cache;
    #[Autowired] protected int $ttl = 3600;
}
```

```yaml
# switon.yml
App\Service\ProductService:
  ttl: 7200
```

- コンテナが `ProductService` を生成する。
- `CacheInterface` を注入する。
- `switon.yml` のスカラー上書きを適用する。

---

## コア概念 {#core-concepts}

### インターフェース優先の自動配線 {#interface-first-autowiring}

`XxxInterface` と `Xxx` が同一名前空間にあるとき、追加バインドなしで DI が解決する。

実装が別名前空間、名前が違う、契約に複数実装があるときは明示設定を使う。

### `#[Autowired]` の値 {#autowired-values}

よくある四形:

- サービスオブジェクト
- スカラー
- 配列
- 遅延解決の `Switon\Core\Lazy`

注入はコンストラクタより前。素の `new` では走らない。

### 共有と新規 {#shared-versus-fresh}

共有サービスは `get()`。新規インスタンスは `make()`。

- `get()` — 安定した能力向け
- `make()` — コマンド、入力オブジェクト、都度のオブジェクト向け

### 名前付きスロット {#named-slots}

ひとつの契約に複数インスタンスが要るときは `Switon\Di\Factory`。プロパティ名がスロットになり、設定がどの名前付きインスタンスを埋めるか決める。

名前がランタイムデータだけで決まるときだけ `NamedLookupInterface`。

### エイリアスリダイレクト {#alias-redirects}

`#[Autowired]` は明示 ID と相対エイリアスも受け付ける。

- `#cache` → `Type#cache` へリダイレクト
- 配線を明示したいときは完全なサービス ID でスロット名を迂回できる

エイリアスは配線の端で使い、通常の業務コードのパターンにしない。

### スコープ {#scope}

DI のシングルトンは **プロセススコープ**。可変なリクエスト／接続状態は共有サービスプロパティではなく `ContextAware` と `ContextManagerInterface` へ。

### 診断 {#diagnostics}

稼働中のコンテナを見る:

```bash
bash bin/console container:inspect --id 'App\Service\ProductService'
bash bin/console container:definitions --filter 'App\Service'
bash bin/console container:instances --filter 'App\Service'
```

---

## 設定 {#configuration}

慣習で足りないときだけ設定が要る:

- サービスが別名前空間にある
- プロパティにスカラー上書きが要る
- 契約に複数の名前付きインスタンスが要る

### 明示バインド {#explicit-binding}

```yaml
# switon.yml
App\Contracts\StorageInterface:
  class: App\Service\S3Storage
```

### 名前付きインスタンス {#named-instances}

```yaml
# switon.yml
App\Service\CacheInterface:
  class: Switon\Di\Factory
  default:
    uri: redis://localhost/0
  cache:
    uri: redis://localhost/1
```

プロパティ名で名前付きスロットを注入する。

---

## 注意点 {#keep-in-mind}

- `new` で autowired オブジェクトを作ると注入がスキップされる。
- `?Type` はオブジェクト注入をオプションにしない。遅延は `Type|Lazy`。
- コンテナをリクエスト状態の保管庫にするとライフサイクルが混ざる。
- DI はサービス・オブジェクト・名前付きスロット・callable 引数を解決する。最終 callable のガードやインターセプタは [Invoker パッケージ](/en/invoker/)。
- ランタイム名は通常 `NamedLookupInterface` 向け。業務コードに ID をハードコードしない。

---

## 次のステップ {#next}

- **プロパティ注入の規則:** [Autowired](autowired.md)
- **名前付きインスタンス:** [Factory](factory.md)
- **FAQ:** [FAQ](faq.md)
- **設計:** [設計の狙い](design.md)
- **初回チュートリアル:** [Tutorial](/en/tutorial/)
