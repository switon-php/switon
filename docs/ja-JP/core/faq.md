---
sidebar_label: Core FAQ
description: Core の導入、App、Scene、Context、パス、Lazy、#[Autowired]。
---

# Core FAQ {#core-faq}

短い回答集です。まず [クイックスタート](README.md#quick-start) を読み、特定の確認が必要ならここへ。

> **前提:** [Core パッケージ](README.md)

## はじめに {#getting-started}

### `switon/core` を直接入れる必要は？ {#direct-install}

Core 契約やヘルパーを直接使うときだけ。多くのスタックは推移依存で入る。[インストール](README.md#installation) を参照。

### 次に何を読む？ {#what-next}

一度 [クイックスタート](README.md#quick-start) を読み、必要に応じて概念セクションかこの FAQ へ。

### プロジェクトルートの見つけ方は？ {#project-root}

`Runtime::getRoot()`。Composer の `autoload.php` 位置からプロジェクトルートを推定します。テストや非標準のブートストラップでは `Runtime::setRoot()` で上書き可能。[パス](README.md#paths) を参照。

## 契約 {#contracts}

### `AppInterface` はいつ使う？ {#when-app-interface}

アプリメタデータだけ: id、name、version、環境、デバッグ、タイムゾーン。[アプリメタデータ](README.md#app-metadata) を参照。

### なぜ `SceneManagerInterface` は別？ {#scene-split}

`cli` や `http` など **現在のランタイムシーン** 用。`AppInterface` に折り込まない。[ランタイムシーン](README.md#runtime-scene) を参照。

### `ContextAware` はいつ？ {#when-context-aware}

可変なリクエスト／接続状態を共有サービスではなくコンテキストオブジェクトに載せたいとき。契約は `ContextManagerInterface`、既定実装は `switon/context`。[コンテキスト状態](README.md#context-state) を参照。

### `PathAliasInterface` はいつ？ {#when-path-alias}

生パスではなく `@root`、`@app`、`@runtime`、`@vendor`、`@resources` やカスタムエイリアスで書きたいとき。[パス](README.md#paths) を参照。

### `#[Communal]` の意味は？ {#what-communal}

**マーカーのみ**。Core はランタイムで共有プロパティ挙動を強制しない。パッケージと作者が「意図的に共有」と示す慣習。

### `Lazy` とは？ {#lazy}

遅延解決したいとき union で `FooInterface|Lazy`。DI パッケージが初回利用で `LazyPropertyProxy` にする。

### `#[Autowired]` で何を注入できる？ {#autowired}

DI パッケージが使うプロパティ注入属性。`instances: true` は配列要素を設定値ではなくサービスインスタンスとして解決。[自動注入](README.md#autowiring) を参照。

## ブートストラップ {#bootstrap}

### 通常コードで `App::get()` を呼べる？ {#app-get}

注入された `AppInterface` か `ContainerInterface` を優先。`App::get()` / `App::make()` はブートストラップ境界か互換境界だけ。[自動注入](README.md#autowiring) を参照。

### なぜ `#[Autowired]` が `null` のまま？ {#autowired-null}

おそらくコンテナや invoker ではなく `new` で作った。注入を期待するならフレームワークに作らせる。[自動注入](README.md#autowiring) を参照。

### `raise()` と `new` の使い分けは？ {#raise}

`{key}` 置換と保存コンテキストが要るなら `Exception::raise()`。素の PHP 例外型だけ扱うなら `new`。[例外](README.md#exceptions) を参照。
