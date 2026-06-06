---
sidebar_label: Invoker パッケージ
description: switon/invoker：DI 実行にガード・インタセプタ、明示オーバーライド優先。
---

# Invoker パッケージ

`switon/invoker` は `Switon\Di\InvokerInterface` を包み、管理対象メソッド呼び出しにガードとインタセプタを付ける。

**主な機能:**

- **単一 callable 契約:** 配列 callable・クロージャ・文字列 callable・invokable オブジェクトが同一入口
- **明示オーバーライド優先:** 位置・名前・型キー・可変長オーバーライドが既定値と DI フォールバックより先
- **DI 実行:** `Switon\Di\InvokerInterface` が callable 実行とパラメータ解決を担う
- **Invocation ガード:** `GuardInterface` が管理対象メソッドの前に実行
- **インタセプタ:** `InterceptorInterface` が管理対象呼び出しを包む
- **トランスポート非依存:** HTTP・CLI・RPC・カスタム dispatch が同じ最終 invoke 段階を共有可能

## インストール {#installation}

**要件:** PHP 8.3 以上

```bash
composer require switon/invoker
```

## クイックスタート {#quick-start}

対象 callable と最終引数オーバーライドが既にある箇所に `InvokerInterface` を注入し、`invoke()` を呼ぶ。

```php
use Switon\Core\Attribute\Autowired;
use Switon\Core\AppInterface;
use Switon\Invoking\InvokerInterface;

class Dispatcher
{
    #[Autowired] protected InvokerInterface $invoker;

    public function dispatch(object $controller, string $action, int $id): mixed
    {
        return $this->invoker->invoke([$controller, $action], ['id' => $id]);
    }
}

class PostController
{
    public function showAction(int $id, AppInterface $app): array
    {
        return ['id' => $id, 'app' => $app->id()];
    }
}
```

ディスパッチャが callable 境界と明示的 `id` を渡す。`AppInterface` は DI にフォールバック。

引数リストがまだリクエスト入力・型付き入力・ビルダーから要るなら、先に [Binding パッケージ](/ja-JP/binding/) で解決する。

## コア概念 {#core-concepts}

### Callable 境界 {#callable-boundary}

Invoker は dispatch 境界で意味のある通常の PHP callable 形式を受け付ける。

- `[$controller, 'showAction']` などの配列 callable
- クロージャ
- 文字列 callable
- invokable オブジェクト

呼び出し側が最終 callable を既に知り、一貫した実行経路が要るときは `invoke()`。

### 引数解決 {#argument-resolution}

最終引数は小さく明示的な順序で解決。

- 明示的位置オーバーライド
- 明示的名前オーバーライド
- 既定値
- 型キー付きオブジェクトオーバーライド
- 未解決オブジェクトパラメータの DI フォールバック

この段階は最終 callable 引数のみ；入力構築は [Binding パッケージ](/ja-JP/binding/) の領域。

### Invocation フック {#invocation-hooks}

メソッド単位フックは invoker 管理のメソッド呼び出しでのみ動く。

- ガード — メソッド本体の前
- インタセプタ — 管理対象呼び出しを包む
- `$service->run()` のような直接呼び出しは両段階をバイパス
- `preHandle()` が `false` を返しても呼び出しはスキップされない；止めるにはスロー
- `postHandle()` は戻り値を参照で受け、置き換え可能

通常のメソッド呼び出しはフック挙動を引き受けない。

## 設定 {#configuration}

Invoker は設定が軽い。通常アプリでは `InvokerInterface` は標準 DI で解決し、パッケージ独自のオプション面はない。

`InvokerInterface` がフック付き入口；`Di\InvokerInterface` は下位の引数解決と callable 実行。
フック契約自体は [Invocation パッケージ](/ja-JP/invocation/) にある。

## 注意点 {#keep-in-mind}

- Invoker は `Di\InvokerInterface` の周りにガードとインタセプタを足す；DI はオートワイア・共有サービス・名前付きスロット・素の callable 実行を担う。
- 引数がまだトランスポート入力・ビルダー・検証から要るときは [Binding パッケージ](/ja-JP/binding/)。
- 直接メソッド呼び出しはガード・インタセプタを起動しない。
- `ReflectionMethod` を既に持っているなら、先に callable 境界を導き、その後 `invoke()`。
- ガードハンドラの `handle()` には現在の `ReflectionMethod` が注入される。

## 次のステップ {#next}

- **invoke 前の引数構築:** [Binding パッケージ](/ja-JP/binding/)
- **コンテナ解決:** [DI パッケージ](/ja-JP/di/)
- **よくある質問:** [FAQ](faq.md)
- **binding との分離:** [設計の狙い](design.md)
