---
sidebar_label: Dict パッケージ
description: switon/dict：型付き辞書、DictValue、OptionReader、ResolvedBy。
---

# Dict パッケージ {#dict-package}

`switon/dict` は、制御された値集合を Switon 内で **型付きアクションパラメータ**、**検証済み入力フィールド**、**共有オプション行** として通すための仕組みです。

**主な機能:**

- **型付きアクション境界:** 生スカラーの繰り返しチェックの代わりに `OrderStatus $status`。
- **プロパティ正規化:** 入力オブジェクトやエンティティが解決済み item 値を保持するときは `#[DictValue(...)]`。
- **共有読み取り契約:** `DictReaderInterface` が型と item 参照、`OptionReaderInterface` がオプション行。
- **型が所有するコード:** `Dict::typeCode()` は明示も、クラス名からの導出も可。
- **既定 item フォールバック:** 欠損値は型の既定に落ちうる。nullable パラメータは `null` のままも可。

---

## インストール {#installation}

**要件:** PHP 8.3 以上

```bash
composer require switon/dict
```

アプリは `Switon\Dict\DictReaderInterface` をひとつの reader 実装にバインドする。同じソースが UI オプションも供するなら `Switon\Dict\OptionReaderInterface` もバインドする。
アクションパラメータ解決に `switon/binding`、`#[DictValue]` 制約に `switon/validation` が前提です。

---

## クイックスタート {#quick-start}

型付き辞書クラスをひとつ定義:

```php
namespace App\Dict;

use Switon\Dict\Dict;

final class OrderStatus extends Dict
{
    public static function typeCode(): ?string
    {
        return 'order.status';
    }
}
```

アクションパラメータで使う:

```php
use App\Dict\OrderStatus;

public function updateAction(OrderStatus $status): void
{
    // $status is a resolved dict item, not a raw string.
}
```

スカラー入力フィールドを解決済み item 値に正規化するときはプロパティでも同じ型:

```php
use App\Dict\OrderStatus;
use Switon\Dict\Attribute\DictValue;

final class UpdateOrderInput
{
    #[DictValue(OrderStatus::class)]
    public string $status;
}
```

同じソースが select や radio も供する場合:

- `Switon\Dict\OptionReaderInterface` をバインド
- 同じバックエンドデータからオプション行を返す

- 入力が無効なら、アクション本体が続く前に解決が失敗する。

---

## コア概念 {#core-concepts}

### 型が所有する Dict {#type-owned-dicts}

`Dict` サブクラスがひとつの制御された値集合を表す。クラス名で type code を定義するか、`typeCode()` で明示する。

`App\Areas\Order\OrderStatus` と `App\Dict\OrderStatus` はどちらも既定で `order.status` を導出する。

### アクションパラメータ {#action-parameters}

`Dict` は `Switon\Binding\Attribute\ResolvedBy` が付き、`Dict` サブクラス型のパラメータは `Switon\Dict\DictResolver` 経由で解決される。

解決は単純な経路:

- 辞書型を読む
- パラメータ名で入力値を読む
- アクションパラメータで値が欠けていれば既定 item を返す
- パラメータが nullable で値が空なら `null`
- 一致すれば解決済み `Dict` item を返す

`getItem()` は保存値（`itemValue`）で一致し、`itemCode` ではない。

### フィールド正規化 {#field-normalization}

`#[DictValue(...)]` はエンティティや入力プロパティ向け。

- 同じ辞書ソースに対してスカラー入力を検証する。
- 受け入れた値を保存 item 値に正規化する。
- フィールドはスカラーで届くが、アプリは辞書由来の意味を持たせたいときに使う。
- アクションパラメータと違い、空入力で `#[DictValue]` が自動で既定 item を選ぶことはない。
- nullable プロパティは `null` に正規化しうる。

### オプション行 {#option-rows}

`OptionReaderInterface::getOptions()` が select・radio・tag 向けの行を返す。

文書化されている行の形:

- `label`
- `value`
- `code`
- `tag`
- `default`
- `builtin`

- `tag` は `int`。
- `builtin` は組み込み辞書データとアプリ所有データを分けるフラグ。

---

## 設定 {#configuration}

reader を `switon.yml` でバインド:

```yaml
Switon\Dict\DictReaderInterface:
  class: App\Dict\ArrayDictReader
```

同じ reader が UI オプションも供するなら `OptionReaderInterface` を同じクラスにバインド。

現在のオプション面を正確に見る:

```bash
bash bin/console config:options 'Switon\Dict\DictReaderInterface'
```

---

## 注意点 {#keep-in-mind}

- クラス名を最終的な dict type code にしたくないときは `typeCode()` を上書きする。
- 空入力を `null` のままにしたいときは nullable を明示する。
- UI が本当に辞書由来の行を要るときだけ `OptionReaderInterface` をバインドする。

---

## 次のステップ {#next}

- **FAQ とエッジケース:** [FAQ](faq.md)
- **設計トレードオフ:** [設計の狙い](design.md)
