---
sidebar_label: Composer Extra FAQ
description: extra の読み方、allow-plugins、キャッシュ欠損、path オーバーレイ。
---

# Composer Extra FAQ {#composer-extra-faq}

**よくある質問と短い回答です。**

> **前提:** [Composer Extra パッケージ](README.md)

## はじめに {#getting-started}

### ランタイムでパッケージの `extra` を読むには？ {#use-extra}

`Switon\ComposerExtra\ComposerExtraInterface` を注入し、`get()`、`all()`、`has()`、`collect()`、`health()` を呼ぶ。
[クイックスタート](README.md#quick-start) を参照。

### Composer 2.2+ で `allow-plugins` が要るのはなぜ？ {#allow-plugins}

Composer 2.2+ ではルートが許可しないプラグインはブロックされる。`config.allow-plugins` に `"switon/composer-extra": true` を足す。
[設定](README.md#configuration) を参照。

### パッケージが無いとき `get()` の戻りは？ {#get-miss}

空配列 `[]`。`null` ではない。存在チェックだけなら `has($packageName)`。
[パッケージメタデータ](README.md#package-metadata) を参照。

### キャッシュファイルが無いとどうなる？ {#cache-missing}

`health()` は例外を投げずに失敗状態を返す。`all()` と `collect()` はキャッシュ欠損時にスローする。
[キャッシュの流れ](README.md#cache-flow) を参照。

### path インストールのオーバーレイはどう読み込まれる？ {#path-overlay}

path／symlink インストールは `ComposerExtra` のキャッシュ読み込み時にマージされ、現在のランタイムが更新するかキャッシュが再構築されるまでメモリから再利用される。

## キャッシュとランタイム {#cache-and-runtime}

### キャッシュはいつ作り直される？ {#when-regenerated}

`composer install` と `composer update` の後、`post-install-cmd` と `post-update-cmd` 経由。
[キャッシュの流れ](README.md#cache-flow) を参照。

### キャッシュの健全性を素早く確認するには？ {#cache-health-check}

`ComposerExtraInterface::health()` を呼び `ok` と `reason` を見る。
[パッケージメタデータ](README.md#package-metadata) を参照。

### 生成キャッシュを手で編集していい？ {#manual-edit}

いいえ。次の install か update でプラグインが `vendor/switon/composer-extra.json` を上書きする。
[注意点](README.md#keep-in-mind) を参照。
