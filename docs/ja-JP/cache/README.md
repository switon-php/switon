---
sidebar_label: Cache パッケージ
description: Switon cache：Redis・PSR-16・JSON・プレフィックス付き clear・ミス検知。
---

# Cache パッケージ {#cache-package}

`switon/cache` は Switon アプリ向けに **Redis バックエンドの** `Psr\SimpleCache\CacheInterface` をひとつにまとめます。読みやすいキー、JSON 値、プレフィックス単位の `clear()`、型付きミス扱いが特徴です。

**主な機能:**

- **標準契約:** `Psr\SimpleCache\CacheInterface` を注入して通常の PSR-16 メソッドを呼ぶ。
- **Redis 向けキー:** `user:123` や `config:v2:app` のような名前で読みやすく保てる。
- **相互運用しやすい値:** 書き込みは JSON で保存し、プロセス間でも見やすく共有しやすい。
- **自動エンコード:** 配列やスカラーは呼び出し側で手動 `json_encode` / `decode` しない。
- **安全な掃除:** `clear()` は **空でないプレフィックス** が必須で、共有 Redis の名前空間を切り離す。
- **バッチ API:** 複数キー向けに `getMultiple()`、`setMultiple()`、`deleteMultiple()`。
- **イベント:** 該当操作で `CacheHit`、`CacheMiss`、`CacheWritten`、`CacheMultipleWritten`、`CacheDeleted`、`CacheCleared` が発火する。

## インストール {#installation}

**要件:** PHP 8.3 以上、`ext-redis`

```bash
composer require switon/cache
```

## クイックスタート {#quick-start}

`Psr\SimpleCache\CacheInterface` を注入し、**cache-aside** の流れに従います。

```php
namespace App\Service;

use stdClass;
use Psr\SimpleCache\CacheInterface;
use Switon\Core\Attribute\Autowired;

class ProductService
{
    #[Autowired] protected CacheInterface $cache;

    public function getFeatured(): array
    {
        $miss = new stdClass();
        $products = $this->cache->get('featured_products', $miss);

        if ($products === $miss) {
            $products = $this->loadFeaturedProducts();
            $this->cache->set('featured_products', $products, 1800);
        }

        return $products;
    }
}
```

- キャッシュとして **`null` もありうる** ときは **専用のミスマーカー** を使う。
- その経路で `null` をキャッシュしないなら、単純な `get()` の真偽チェックでもよい。

## コア概念 {#core-concepts}

### キー {#keys}

**安定した** アプリ所有のキー文字列と JSON 向きのペイロードにします。

- `user:123` や `config:v2:app` は典型的な Redis の慣習に合う。
- 読み取りは配列・スカラー・`null` にデシリアライズされる。
- リッチなドメインオブジェクトが必要ならアプリ側で再構築する。

### 値 {#values}

保存される値はすべて Redis に入る前に JSON エンコードを通る。

- PHP 固有のシリアライズなしで他サービスも読める。
- アプリから渡すのはネイティブの配列とスカラーのみ。

### ミス {#misses}

**未設定のキー** と **`null` のキャッシュ値** を区別したいときはセンチネルオブジェクトを選ぶ。

```php
$miss = new \stdClass();
$value = $cache->get($key, $miss);

if ($value === $miss) {
    // load and set
}
```

### TTL {#size-and-ttl}

`set()` は **秒を表す整数**、**`DateInterval`**、または **`null`**（TTL なし）を受け付ける。

### プレフィックス {#prefixes}

- 既定プレフィックス: `cache:`。
- `clear()` は設定されたプレフィックスに一致するキーだけ削除し、無関係な Redis のデータベースと切り離す。

## 設定 {#configuration}

既定では `Psr\SimpleCache\CacheInterface` が `Switon\Caching\SimpleCache` にバインドされます。

**カスタムプレフィックス**、**別の Redis クライアント**、**複数キャッシュ名前空間** が必要なときだけ設定を足す。

### 基本設定 {#basic-configuration}

```yaml
# switon.yml
Psr\SimpleCache\CacheInterface:
  prefix: 'myapp:'
```

### 複数インスタンス {#multiple-instances}

並列の名前空間が必要なら `Switon\Di\Factory` を使います。

```yaml
# switon.yml
Psr\SimpleCache\CacheInterface:
  class: Switon\Di\Factory
  default:
    class: Switon\Caching\SimpleCache
    prefix: 'cache:'
  users:
    class: Switon\Caching\SimpleCache
    prefix: 'users:'
```

プロパティ名に合わせたエイリアスを注入します。例: `#[Autowired] protected CacheInterface $users;`

```php
use Psr\SimpleCache\CacheInterface;
use Switon\Core\Attribute\Autowired;

class UserCacheWarmer
{
    #[Autowired] protected CacheInterface $users;
}
```

### オプションの確認 {#option-discovery}

```bash
bash bin/console config:options 'Psr\SimpleCache\CacheInterface'
```

## 運用 {#operations}

### バッチ操作 {#batch-operations}

キーがまとまっているときはバッチヘルパーを使います。

```php
$users = $cache->getMultiple(['user:1', 'user:2']);

$cache->setMultiple([
    'user:1' => ['id' => 1],
    'user:2' => ['id' => 2],
], 3600);

$cache->deleteMultiple(['user:1', 'user:2']);
```

### CLI コマンド {#cli-commands}

プレフィックスで一覧・削除:

```bash
bash bin/console cache:list
bash bin/console cache:list --showTtl
bash bin/console cache:clear
```

## 注意点 {#keep-in-mind}

- **`clear()` に空プレフィックスを渡さない** — 無関係なキーまで消える可能性がある。
- `cache:list` も同じ「空プレフィックス禁止」規則。
- `get()` が返すのは **デコード済みの配列やスカラー** で、任意の PHP オブジェクトではない。
- 壊れた JSON は **サイレントミスではなくパースエラー** になる。
- ホットパスで `has()` のあと `get()` すると Redis が **二往復** になる。
- `clear()` は **名前空間の掃除** と捉え、グローバルな原子パージではない。

## 次のステップ {#next}

- **運用の質問:** [FAQ](faq.md)
- **トレードオフ:** [設計の狙い](design.md)
