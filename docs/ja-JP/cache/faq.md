---
sidebar_label: Cache FAQ
description: Switon cache の導入・設定・プレフィックス・CLI・障害時。
---

# Cache FAQ {#cache-faq}

セットアップ・設定・運用上の短い回答です。

> **前提:** [Cache パッケージ](README.md)

## はじめに {#getting-started}

### コードにキャッシュを足すには？ {#add-caching}

`Psr\SimpleCache\CacheInterface` を注入し cache-aside で実装します。[クイックスタート](README.md#quick-start) を参照。

### なぜ `get()` はオブジェクトではなく配列を返す？ {#json-values}

値は保存前に JSON エンコードされ、読み取りは **配列**・**スカラー**・**`null`** にデコードされます。オブジェクトが必要なら明示的にハイドレートしてください。

### キャッシュに `null` を保存できる？ {#cache-null}

はい。**キャッシュミス** と区別するために **別のミスマーカー** を使います。

### キーに使える文字は？ {#key-characters}

`user:123` や `config:v2:app` のような区切りは Switon の既定に合います。バイトを禁止するバックエンドと共有するときはアルファベットを絞ってください。

### TTL の設定は？ {#size-and-ttl}

**整数（秒）**、**`DateInterval`**、または期限なしの **`null`** を渡します。

## 設定 {#configuration}

### どこで設定する？ {#configure}

`switon.yml` の `Psr\SimpleCache\CacheInterface` 配下です。隔離の主なノブは **`prefix`**（既定は `cache:`）。

### なぜプレフィックスが空だと `clear()` が例外になる？ {#prefix-required}

制約が無いと `clear()` が **無関係な Redis キー** を巻き込む可能性があるため、パッケージは早期に失敗させます。

`cache:list` も同じ規則です。

### 複数キャッシュを公開できる？ {#multiple-instances}

はい — `Switon\Di\Factory` のエイリアスや、異なるプレフィックスの別バインドを使います。

## 運用 {#operations}

### Redis の往復を減らしたい {#batch}

複数キーをまとめて扱うときは **`getMultiple` / `setMultiple` / `deleteMultiple`** を優先してください。

### `get()` の前に `has()` すべき？ {#has-before-get}

いいえ。**ミスマーカー** を組み合わせるか、`get` の既定で十分なら **`has` を挟まない** で往復を増やさない。

### CLI でプレフィックスを確認するには？ {#cli}

`cache:list` と `cache:clear` を実行します。プレフィックスはランタイム設定（`cache:` かカスタムか）と一致させます。

## トラブルシュート {#troubleshooting}

### Redis やデコードが失敗したら？ {#error-handling}

キャッシュは **最適化** とみなします。キャッシュ操作を `\Throwable` で囲み、権威あるデータストアへフォールバックしてください。

壊れた JSON は無害なミスではなく **パースエラー** — 明示的に処理してください。

Redis の接続エラーとペイロード欠損は別問題です。

`clear()`（`CachePrefixRequiredException`）と **`cache:list`** はどちらも空プレフィックスを安全のため拒否します。

### `get()` が `null` だがデータがあるはず {#cache-miss}

「キャッシュが空」を `null` で表しているならミスマーカーを使ってください。

### キャッシュ利用をユニットテストするには？ {#testing}

`CacheInterface` をテストダブルに差し替えるか、捨てられる Redis データセットで統合テストします。
