---
sidebar_label: Autowired
description: #[Autowired]：注入タイミング、値の形、instances、エイリアス。
---

# `#[Autowired]` {#autowired}

`#[Autowired]` はコンストラクタより前に型付きプロパティを注入します。

**主な機能:**

- **インターフェース優先:** 同一名前空間の `XxxInterface` → `Xxx` を自動解決。
- **コンストラクタ前注入:** `__construct()` 内ですでに注入済みの値が使える。
- **値の形:** サービス、スカラー、配列、`Switon\Core\Lazy`。
- **配列の instances:** `instances: true` で配列要素をサービスオブジェクトに解決。
- **明示リダイレクト:** `#name` と完全なサービス ID でスロットを上書き。

---

## クイックスタート {#quick-start}

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Redis\ClientInterface;

class ProductService
{
    #[Autowired] protected ClientInterface $redis;
    #[Autowired] protected int $ttl = 3600;
}
```

```yaml
# switon.yml
App\Service\ProductService:
  ttl: 7200
```

`#[Autowired]` が `ClientInterface` を注入し、構築前にスカラー上書きを適用する。

---

## コア概念 {#core-concepts}

### 注入タイミング {#injection-timing}

`#[Autowired]` プロパティはコンストラクタより前に注入される。素の `new` では注入されない。

### 自動配線の規則 {#autowiring-rules}

`XxxInterface` と `Xxx` が同一名前空間なら追加バインド不要。

明示設定が要るのは:

- 実装が別名前空間
- 型名と実装クラス名が違う
- 契約に複数実装がある

### 値の形 {#value-forms}

よくある形:

- サービスオブジェクト
- スカラー
- 配列
- 遅延解決の `Switon\Core\Lazy`

配列では設定値が既定配列とマージされ、`null` でキー削除、`Traversable` で配列置換。
配列をクラス名ではなく解決済みサービスにしたいときは `instances: true`。

### 明示配線 {#explicit-wiring}

プロパティを `Type#name` に向けるときは `#name`。
対象をはっきりさせるときは完全なサービス ID。

---

## 注意点 {#keep-in-mind}

- `new` で autowired オブジェクトを作ると注入がスキップされる。
- 型宣言が無いプロパティは自動注入できない。
- インターフェースがあるのに具象クラスを既定にするのは誤りになりやすい。
- `?Type` はオブジェクト注入をオプションにしない。
- 遅延は nullable オブジェクトではなく `Type|Lazy`。

---

## 次のステップ {#next}

- **コンポーネント概要:** [DI パッケージ](README.md)
- **名前付きインスタンス:** [Factory](factory.md)
- **FAQ:** [FAQ](faq.md)
- **設計:** [設計の狙い](design.md)
- **チュートリアル:** [Tutorial](/en/tutorial/)
