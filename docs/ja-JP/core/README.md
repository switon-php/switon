---
sidebar_label: Core パッケージ
description: switon/core：共有契約、#[Autowired]、App、Scene、Context、ヘルパー。
---

# Core パッケージ {#core-package}

`switon/core` は Switon 全体が載る **共有の `Switon\Core` 契約・属性・ヘルパー** を提供します。機能ドメインや起動順序は持たず、フレームワーク共通の語彙を揃えます。

**主な機能:**

- **プロパティ自動注入:** `#[Autowired]` と `Lazy` が通常の注入経路。`instances: true` で配列要素をサービスインスタンスに解決。
- **アプリメタデータ:** `AppInterface` が id、name、version、環境、デバッグ、タイムゾーンを読む。
- **ランタイムシーン:** `SceneManagerInterface` がアプリメタデータとシーンを分離。
- **コンテキスト状態:** `ContextAware` と `ContextManagerInterface` で、可変なリクエスト／コルーチンスコープ状態を共有サービスから外す。既定のマネージャ実装は `switon/context`。
- **共有ヘルパー:** JSON、パス、文字列、命名、ファイル、乱数、バックトレース、グローバルヘルパーをフレームワーク一貫で提供。
- **カテゴリ契約:** `Categorizable` と `Categorized` がログやルーティング向けの安定したカテゴリキーを与える。
- **契約の分離:** Core はインターフェースとマーカーを公開し、既定実装は兄弟パッケージが担う。

---

## インストール {#installation}

**要件:** PHP 8.3 以上、`ext-json`

```bash
composer require switon/core
```

---

## クイックスタート {#quick-start}

- `AppInterface` を注入し、`#[Autowired]` に任せる。
- アプリメタデータは `AppInterface` を使う。
- 静的ブートストラップの橋がどうしても必要なときだけ `App` を使う。

```php
use Switon\Core\AppInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Json;

class StatusReporter
{
    #[Autowired] protected AppInterface $app;

    public function snapshot(): string
    {
        return Json::stringify([
            'app' => $this->app->name(),
            'env' => $this->app->env(),
            'debug' => $this->app->isDebug(),
        ], JSON_PRETTY_PRINT);
    }
}
```

---

## コア概念 {#core-concepts}

### 自動注入 {#autowiring}

- `#[Autowired]` が注入可能なプロパティを示す。
- 型グラフには載せつつ初回利用まで解決を遅らせたいときは `Lazy`。
- `FooInterface|Lazy` のような union と組み合わせ、`switon/di` が `LazyPropertyProxy` で包む。

### ブートストラップの橋 {#bootstrap-bridge}

- `AppInterface` はアプリメタデータの読み取り契約。
- `App` はブートストラップと互換境界用の静的ブリッジ。
- コンテナが配線済みなら `App::get()` で共有サービス、`App::make()` で新規インスタンス。
- どちらも通常のアプリコードからは呼ばない。
- フレームワーク起動後は注入された `AppInterface`、`ContainerInterface`、`MakerInterface` を優先。

### アプリメタデータ {#app-metadata}

- `AppInterface` は id、name、version、環境、デバッグ、タイムゾーン **のみ**。
- ランタイムシーンや可変なリクエスト／コルーチンスコープデータは別置き。

### ランタイムシーン {#runtime-scene}

- `SceneManagerInterface` が現在のシーンを読み書き。
- `#[Scene]` がブートストラップ入口クラスにシーンを付ける。

### コンテキスト状態 {#context-state}

- `ContextAware` はコンテキストオブジェクトに載せるべき可変なリクエスト／接続状態を示す。
- `ContextManagerInterface` が契約を定義し、`switon/context` が既定マネージャを提供。
- 共有してはいけないコンテキストデータには `ContextIsolated`。
- `ContextConnScoped` は接続ライフサイクル用の別マーカー。`ContextManager` はこれで分岐しない。
- `#[Communal]` は **意図的に共有するプロパティ** のマーカーのみ。

### カテゴリ {#categories}

- `Categorizable` がログ・フィルタ・ルーティング向けの安定したカテゴリ文字列を与える。

### パス {#paths}

- `Runtime::getRoot()` がプロジェクトルートを解決。
- `PathAliasInterface` が `@root`、`@app`、`@runtime`、`@vendor`、`@resources` などのエイリアスを展開。

### ヘルパー {#helpers}

- `Json`、`Strings`、`Naming`、`ClassName`、`Backtrace`、`Filesystem`、`Random`、`Clock`、`FilterMatcher` が共通ヘルパー。
- `make()`、`env()`、`json()`、`console_log()`、`array_first()`、`array_last()` などのグローバルヘルパーは Core と一緒にオートロードされる。

### 例外 {#exceptions}

- `Exception::raise()` が `{key}` プレースホルダと保存コンテキスト付きでフレームワーク例外を生成・送出。

### アプリケーション境界 {#application-boundary}

- `AppInterface` がアプリメタデータを所有。
- `SceneManagerInterface` がランタイムシーンを所有。
- アプリケーション起動はブートストラップ側に置く。

### PHP 属性 {#php-attributes}

- `#[Autowired]`、`#[Scene]`、`#[ResourceAlias]`、`#[Communal]` などが共有アプリモデルを支える。

### プロバイダ {#providers}

- サービスプロバイダはパッケージ配線とブートストラップフック用。
- 通常のアプリコードは `switon.yml` と DI に留める。

---

## 注意点 {#keep-in-mind}

- `new` で autowired オブジェクトを作ると `#[Autowired]` は効かない。
- `AppInterface` はメタデータ専用。リクエスト状態は別へ。
- 可変状態は `ContextAware` + `ContextManagerInterface` へ。共有サービス本体に載せない。
- テストやブートストラップでルート検出を上書きするときは `Runtime::setRoot()`。
- `App::get()` はブートストラップ境界だけ。
- シーンをアプリメタデータに混ぜない。ランタイム状態側へ。

---

## 次のステップ {#next}

- **よくある質問:** [FAQ](faq.md)
- **トレードオフ:** [設計の狙い](design.md)
- **パッケージ起動順:** [Kernel パッケージ](/en/kernel/)
- **Context の実装詳細:** [Context パッケージ](/ja-JP/context/)
