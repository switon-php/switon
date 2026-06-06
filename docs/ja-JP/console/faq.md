---
sidebar_label: Console FAQ
description: プログレスバー、色、バインディング、幅キャッシュ、switon/cli なしで使うには。
---

# Console FAQ {#console-faq}

セットアップとターミナル出力の短い回答です。

> **前提:** [Console パッケージ](README.md)

## はじめに {#getting-started}

### プログレスバーを足すには？ {#add-progress-bar}

`ProgressBarInterface` を注入し、`start()`、`advance()`、`finish()` を呼ぶ。全体件数が分かるなら `start()` に渡す。

### 端末出力に色を付けるには？ {#color-output}

`Switon\Core\ConsoleInterface` 実装（例: `Switon\Cli\Console`）に `Colors` を合わせて使う。

### このパッケージはバインディングを自分で登録する？ {#bindings}

いいえ。原語だけを提供し、アプリの配線はしない。

## 挙動 {#behavior}

### 端末幅が変わらないように見える {#width-cache}

`TerminalWidthDetector` は検出幅をキャッシュする。起動後にサイズが変わったら `clearCache()` を呼ぶ。

### プログレスバーが最後に一気に進む {#bar-jumps}

`setProgress()` は現在の最大値へクランプし、`finish()` が最終描画を強制する。

### `switon/cli` なしで使える？ {#without-cli}

はい。ただし出力を描画するには何らかの `ConsoleInterface` 実装がまだ必要。
