---
sidebar_label: DI FAQ
description: DI の注入、get/make、Factory、コンテナ診断、例外。
---

# DI FAQ {#di-faq}

短い回答集。まず [クイックスタート](README.md#quick-start)、確認が要るときにここへ。

> **前提:** [DI パッケージ](README.md)

## はじめに {#getting-started}

### 依存を注入するには？ {#inject-dependency}

型付き `#[Autowired]` プロパティを宣言し、コンテナに作らせる。[クイックスタート](README.md#quick-start) を参照。

### 自動解決はいつ効く？ {#when-auto-resolution-works}

`XxxInterface` と `Xxx` が同一名前空間のとき。[インターフェース優先](README.md#interface-first-autowiring) を参照。

### スカラーや配列は？ {#inject-scalars-or-arrays}

クラス設定か `make()` パラメータ。配列マージと `instances: true` は [Autowired](autowired.md)。

### `get()` と `make()` の選び方 {#choose-get-or-make}

共有サービスは `get()`、都度のオブジェクトは `make()`。[共有と新規](README.md#shared-versus-fresh) を参照。

## 名前付きインスタンス {#named-instances}

### 複数インスタンスのどれかを注入 {#multiple-instances}

コード上スロットが安定しているなら `Factory`。名前がランタイムでしか分からないなら `NamedLookupInterface`。[名前付きスロット](README.md#named-slots) を参照。

### `?Type` でオブジェクト注入はオプション？ {#optional-dependency-shape}

いいえ。`?Type` は PHP の型だけを変える。遅延は `Type|Lazy`。

## トラブルシュート {#troubleshooting}

### `#[Autowired]` が `null` のまま {#autowired-null}

おそらくコンテナや injector ではなく `new` で作った。[注意点](README.md#keep-in-mind) を参照。

### コンテナの調査は？ {#inspect-container}

`container:inspect --id '...'` で1サービス、`container:definitions --filter '...'` で定義、`container:instances --filter '...'` で解決済みシングルトン。

### まず見る例外は？ {#important-exceptions}

`NotFoundException`、`MissingConfigurationException`、`MissingTypeDeclarationException`、`InterfaceAutowiringException`、`ServiceAlreadyResolvedException` から。[README](README.md#keep-in-mind) の主な失敗型を参照。
