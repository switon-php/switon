---
sidebar_label: Context パッケージ
description: switon/context：サービス所有の可変状態をリクエスト／コルーチンスコープへ。
---

# Context パッケージ {#context-package}

`switon/context` は **共有サービス本体に可変状態を載せない** ために使います。現在のリクエストやコルーチンスコープに従うデータが長寿命サービスに必要なとき、**ひとつのコンテキストオブジェクト** にまとめます。

**主な機能:**

- **所有者に紐づく状態:** サービスが専用の `*Context` オブジェクトをひとつ持つ。
- **ランタイム対応の格納:** 非コルーチン／コルーチンどちらのランタイムでも同じサービスコードが動く。
- **子スコープの制御:** 子コルーチンは既定で親コンテキストを継承。`ContextIsolated` で新しい状態と子スコープ用イベントを選べる。
- **Core 契約優先:** アプリは `Switon\Core\ContextManagerInterface` を注入し、パッケージが既定バインドを供する。

---

## インストール {#installation}

**要件:** PHP 8.3 以上

```bash
composer require switon/context
```

---

## クイックスタート {#quick-start}

1. 現在のリクエスト／コルーチンスコープに従うべき可変フィールド用に `*Context` クラスをひとつ作る。
2. `Switon\Core\ContextAware` を実装し、`Switon\Core\ContextManagerInterface` を注入する。
3. 状態はサービス本体ではなく `getContext()` 経由で読み書きする。

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ContextAware;
use Switon\Core\ContextManagerInterface;

final class RequestStateContext
{
    public string $userId = '';
}

final class RequestState implements ContextAware
{
    #[Autowired] protected ContextManagerInterface $contextManager;

    public function getContext(): RequestStateContext
    {
        return $this->contextManager->getContext($this);
    }

    public function setUserId(string $userId): void
    {
        $this->getContext()->userId = $userId;
    }

    public function userId(): string
    {
        return $this->getContext()->userId;
    }
}
```

---

## コア概念 {#core-concepts}

### 所有者に紐づく状態 {#owner-bound-state}

コンテキストクラスが可変フィールドを持ち、サービスは振る舞いを持つ。値が現在のリクエストやコルーチンスコープと一緒にリセットされるべきなら、**サービス本体に直接** 持たない。

### スコープの解決 {#scope-resolution}

`Switon\Core\ContextManagerInterface` が所有者ごと・ランタイムスコープごとにひとつのコンテキストを返す。

- 非コルーチンでは、同じ所有者は現在のマネージャインスタンスでは同じコンテキストオブジェクトになる。
- コルーチンでは、既定で子は親のコンテキストを再利用する（すでに存在するとき）。
- コルーチンモードでもまだコルーチン内にいないコードは、ルート格納へフォールバックする。
- 子ローカルな変更が必要なら `ContextIsolated` で新しい子コンテキストを選ぶ。

子スコープのイベント:

- `ChildContextInherited`
- `ChildContextCreated`
- `ChildContextPropagated`

### マーカー契約 {#marker-contracts}

- `ContextAware` — 状態を所有するサービスを示す。
- `ContextIsolated` — 子コルーチン間で共有してはいけないコンテキストクラスに付ける。
- `ContextConnScoped` — 接続ライフサイクル用の状態のマーカー。このパッケージが特別扱いするのは `ContextIsolated` のみ。

### コンテキストクラスのルール {#context-class-rules}

- `getContext()` は **読み込み可能なコンテキスト型ひとつ** を宣言する。
- コンテキストクラスは **必須引数なし** でインスタンス化できること。
- 不正な宣言は `Switon\Context\Exception\ContextException`。

---

## 設定 {#configuration}

このパッケージ用の `switon.yml` ブロックは無い。

- アプリコードでは `Switon\Core\ContextManagerInterface` を注入する。既定実装はパッケージが用意する。
- `Switon\Context\ContextManagerInterface` はフレームワーク／テスト向けのヘルパー契約で、アプリ正面の契約ではない。

---

## 注意点 {#keep-in-mind}

- 可変なリクエスト状態はコンテキストオブジェクトへ。サービス本体に乗せない。
- 意図的に共有するプロパティには `#[Communal]`。可変なリクエスト状態は `Context` 側へ。
- 子が親と状態を共有してはいけないときだけ `ContextIsolated` を使う。
- `resetContexts()` が消すのは **非コルーチンキャッシュだけ**。ルートのフォールバック格納は残る。
- 一回限りの受け渡しにはリクエスト属性の方が簡単なことが多い。無理にコンテキストクラスを増やさない。

---

## 次のステップ {#next}

- **Context とリクエスト属性の比較:** [HTTP Request](/en/http/request)
- **伝播と分離の詳細:** [設計の狙い](design.md)
- **実装の進化（8 ステップ）:** [Context 内部](internals/evolution.md)
- **導入のFAQ:** [FAQ](faq.md)
