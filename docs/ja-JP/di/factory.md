---
sidebar_label: Factory
description: Switon\Di\Factory：名前付きスロット、#default、NamedLookup。
---

# `Factory` {#factory}

`Switon\Di\Factory` はひとつの契約を複数の名前付きインスタンスにマップする。プレーンな型は `#default` に解決される。

**主な機能:**

- **プロパティ名スロット:** `$cache` → `Type#cache`。
- **既定フォールバック:** プレーンな `Type` → `#default`。
- **エイリアススロット:** ひとつのスロットが `#name` で別スロットを指せる。
- **ランタイム参照:** 名前がデータ由来のときは `NamedLookupInterface`。
- **分離バインド:** 名前付きスロットはメインの `Type` バインドとは別。

---

## クイックスタート {#quick-start}

```yaml
# switon.yml
Switon\Redis\ClientInterface:
  class: Switon\Di\Factory
  default:
    uri: redis://localhost:6379/0
  cache:
    uri: redis://localhost:6379/1
  session:
    uri: redis://localhost:6379/2
```

```php
use Switon\Core\Attribute\Autowired;
use Switon\Redis\ClientInterface;

class MyService
{
    #[Autowired] protected ClientInterface $redis;
    #[Autowired] protected ClientInterface $cache;
    #[Autowired] protected ClientInterface $session;
}
```

`$redis` は `default`、`$cache` と `$session` は各スロット名に一致。

---

## コア概念 {#core-concepts}

### スロットの一致 {#slot-matching}

- プロパティ名がスロット名。
- 最初の一致は `Type#propertyName`。
- `Type` は `#default` にフォールバック。
- `default` が無く名前が一致しないと早期失敗。

### 明示スロットリダイレクト {#explicit-slot-redirects}

プロパティ名とスロット名が違うときは `#name`。

```yaml
App\Service\MyService:
  redis: '#cache'
```

### ランタイム参照 {#runtime-lookup}

- 対象名がランタイムデータから来るときだけ `NamedLookupInterface`。
- スロットがコード上固定ならプロパティ注入の方が単純。

---

## 注意点 {#keep-in-mind}

- `remove(Type::class)` は `Type#name` エントリを消さない。
- `default` スロットが無いと未一致名は失敗する。
- 安定したスロット名に `NamedLookupInterface` を使うのは通常経路ではない。

---

## 次のステップ {#next}

- **コンポーネント概要:** [DI パッケージ](README.md)
- **プロパティ注入:** [Autowired](autowired.md)
- **FAQ:** [FAQ](faq.md)
- **設計:** [設計の狙い](design.md)
- **チュートリアル:** [Tutorial](/en/tutorial/)
