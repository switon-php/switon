---
sidebar_label: CLI FAQ
description: CLI コマンド追加、bare コマンド、オプション、Hidden、入出力。
---

# CLI FAQ {#cli-faq}

**よくある質問と短い回答です。**

> **前提:** [CLI パッケージ](README.md)

## はじめに {#getting-started}

### コマンドを追加するには？ {#add-command}

スキャン対象パスに、public な `*Action()` を持つ `XxxCommand` を置き、アプリ入口から実行します。
[クイックスタート](README.md#quick-start) を参照。

### 素の `command`（アクション無し）にしたい {#bare-command}

クラスに `defaultAction()` を足します。
素の `command` 呼び出しはそこへルーティングされます。
[ルーティング](README.md#routing) を参照。

### オプションはアクションにどう届く？ {#options-into-action}

名前付きオプションが先、残りを位置引数が埋めます。
[パラメータ束縛](README.md#parameter-binding) を参照。

### サポート用コマンドを隠せる？ {#hide-command}

はい。`#[Hidden]` で既定の `list` から外します。
[可視性](README.md#visibility) を参照。

### なぜ `list` にコマンドが無い？ {#missing-from-list}

多くは未発見か隠れているかです。
まずスキャンパスを確認し、`bash bin/console list --all` を試してください。
[可視性](README.md#visibility) を参照。

## 出力 {#output}

### 行を出したり入力を聞いたり {#console-input-output}

`ConsoleInterface` を注入します。

### プログレスバー {#progress-bar}

`Switon\Console\ProgressBarInterface` を注入します。

### シェル補完 {#shell-completion}

組み込みの completion コマンドで生成し、シェル起動スクリプトにインストールします。
