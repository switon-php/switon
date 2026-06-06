---
sidebar_label: Invoker FAQ
description: callable 実行、フック、dispatch 境界。
---

# Invoker FAQ

callable 実行・フック・dispatch 境界への短い回答です。

> **前提:** [Invoker パッケージ](README.md)

## はじめに {#getting-started}

### 直接呼び出しではなく invoker 入口を使うのはいつ？ {#direct-call}

呼び出し側が DI フォールバック・ガード・インタセプタ付きの管理された最終呼び出しを一本化したいときは `InvokerInterface`。[クイックスタート](README.md#quick-start) を参照。

ガード・インタセプタなしの下位 callable 実行だけなら `Di\InvokerInterface`。
契約は [Invocation パッケージ](/ja-JP/invocation/) で定義。

### invoker がリクエスト入力から引数を組み立てる？ {#build-arguments}

いいえ。入力・型付き入力・ビルダー由来なら先に [Binding パッケージ](/ja-JP/binding/)。

### どの callable 形式が使える？ {#callable-shapes}

配列 callable・クロージャ・文字列 callable・invokable オブジェクト。[Callable 境界](README.md#callable-boundary) を参照。

## 境界 {#boundaries}

### パラメータ値はどこから？ {#parameter-values}

Invoker 自体は明示オーバーライド・既定値・型キーオブジェクト・DI フォールバック。HTTP や CLI などトランスポート由来なら [Binding パッケージ](/ja-JP/binding/)。

### 直接呼び出しでガード・インタセプタが動かない理由は？ {#direct-hooks}

フックは `invoke()` 経由でのみ。素の呼び出しは invocation パイプラインをバイパス。

`preHandle()` の `false` は呼び出しをスキップしない；止めるにはスロー。`postHandle()` は戻り値を置き換え可能。

### invoker が CLI 解析やショートオプションを持つ？ {#cli-parsing}

いいえ。CLI 解析は `switon/cli`；invoker は最終 callable のみ実行。

### パッケージ固有の設定が要る？ {#configuration}

通常不要。Invoker は設定が軽く、通常の DI 配線を使う。

ガードハンドラ自体は invoke 時に現在の `ReflectionMethod` を受け取る。

## トラブルシュート {#troubleshooting}

### オブジェクトパラメータがコンテナから来る {#object-container}

組み込み DI フォールバック。先に binding パイプラインが要るなら [Binding パッケージ](/ja-JP/binding/) で引数を解決。

### invoker を使うコードのテストは？ {#testing}

業務ロジックは直接テスト。ガード・インタセプタは管理された invocation が検証対象のとき `InvokerInterface` 経由でテスト。
