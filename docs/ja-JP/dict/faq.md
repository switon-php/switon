---
sidebar_label: Dict FAQ
description: Dict の適用範囲、reader、欠損値、enum との違い。
---

# Dict FAQ {#dict-faq}

適用範囲・境界・reader 設定への短い回答です。

> **前提:** [Dict パッケージ](README.md)

## 適用範囲 {#fit}

### `dict` はスキーマパッケージ？ {#schema-package}

いいえ。型付き利用と読み取り契約を標準化する。固定テーブルレイアウトは定義しない。

### 検証だけのパッケージ？ {#only-validation}

いいえ。検証は経路のひとつ。同じ辞書型がアクションパラメータと UI オプションも駆動する。

### `enum` と同じ？ {#same-as-enum}

いいえ。`enum` はコード所有の閉じた集合向け。`dict` はランタイム制御の値集合を型付きで使いたいとき向け。

## セットアップ {#setup}

### reader のバインドは必須？ {#bind-reader}

はい。`DictReaderInterface` をアプリ所有の reader にバインド。UI がオプション行も要るときだけ `OptionReaderInterface` も。

### 現在のオプション面の確認は？ {#inspect-options}

`bash bin/console config:options 'Switon\Dict\DictReaderInterface'`。

## 挙動 {#behavior}

### 値が欠けているときは？ {#missing-value}

非 nullable のアクションパラメータは既定 item があればそこへフォールバック。nullable は `null` になりうる。`#[DictValue]` プロパティは既定 item を自動選択せず、受け入れた入力を正規化するか、nullable の空入力は `null` のまま。

### `typeCode()` が空だと？ {#empty-type-code}

解決は失敗する。`typeCode()` を上書きするか、導出できるクラス名を使う。

### 同じ reader で辞書とオプション両方？ {#same-reader}

はい。lookup とオプション行を同じソースが持つときの通常パターン。

### `getItem()` は何に一致する？ {#item-match}

保存値（`itemValue`）。`itemCode` ではない。
