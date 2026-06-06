---
sidebar_label: Crypto FAQ
description: Crypto の導入、mode、検証、RotatingCipher、トラブルシュート。
---

# Crypto FAQ {#crypto-faq}

セットアップ・検証・移行に関する短い回答です。

> **前提:** [Crypto パッケージ](README.md)

## はじめに {#getting-started}

### `switon/crypto` の始め方は？ {#start-using}

`Switon\Crypto\CipherInterface` を注入し `encrypt()` / `decrypt()` を呼ぶ。[クイックスタート](README.md#quick-start) を参照。

### 新規アプリは `derived` / `raw` / `auto` のどれ？ {#pick-mode}

新規データは `derived`。本当の小文字 hex キーだけ `raw`。旧形式を橋渡しするときだけ `auto`。

### 新しいキーの生成は？ {#generate-key}

`bash bin/console key:generate`。文字数は `--length`、小文字は `--lowercase`。

## 設定 {#configuration}

### なぜ `MissingSecretException`？ {#missing-secret}

派生キー扱いなのに空の `secret` がある。`switon.yml` に `secret` を置くか、入力が本当に hex のときだけ `raw` に切り替える。

### 現在のオプションの確認は？ {#inspect-options}

`bash bin/console config:options 'Switon\Crypto\CipherInterface'`。

### 最初に触るオプションは？ {#important-options}

`secret`、`mode`、`method`、`algo` から。相互運用の対象が決まるまで他は触らない。

### `RotatingCipher` の配線は？ {#wire-rotating-cipher}

`CipherInterface` 実装として `RotatingCipher` をバインドし、`newCipher` と `legacyCipher` の両スロットを渡す。`newCipher` は現行設定、`legacyCipher` は旧設定。
[キーローテーション](README.md#key-rotation) を参照。

## 検証 {#validation}

### 組み込みルールは？ {#built-in-rules}

`ascii`、`utf8`、`json`、`numeric`、`base64`、`uuid`、`email`。先頭に `?` で空文字列を許可。

### 独自ルールは？ {#custom-rule}

はい。callable `fn(string $value): bool` を渡すか、`ValidatorInterface` を差し替える。

### なぜ `RotatingCipher::decrypt()` はルール必須？ {#rotation-rule}

フォールバック前に新 cipher 経路を判定するため。検証をスキップしたいときは `Cipher` を直接使う。

### 検証失敗でもレガシーに落ちるのはなぜ？ {#validation-fallback}

`RotatingCipher` は新 cipher からの任意の `Switon\Crypto\Exception`（`ValidationFailedException` 含む）をローテーション経路の失敗とみなす。移行中に旧暗号文を読むための意図的な挙動。

## トラブルシュート {#troubleshooting}

### 短い入力で復号が失敗する {#short-input}

ペイロードが IV より短い（AEAD では IV+タグより短い）。バイナリ全体を欠けずに渡しているか確認。

### `key:generate` の出力は？ {#key-generate-output}

既定はランダム**文字列**（OpenSSL 生バイトではない）。小文字が要るなら `--lowercase`。

### レガシーでも失敗する {#legacy-failure}

新・レガシー両方が拒否したか、フォールバック経路で暗号以外の例外が出たか。上流の例外クラスを先に確認。

### テストの考え方 {#testing}

テストでも同じ `CipherInterface` 契約を使い、フィクスチャは小さく。パッケージテストに往復と失敗分岐の例がある。
