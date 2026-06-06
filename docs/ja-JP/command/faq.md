---
sidebar_label: Command FAQ
description: Command 発見、可視性、Tool、ShortOption、help の出どころ。
---

# Command FAQ {#command-faq}

発見・可視性・ツールメタ・別名への短い回答です。

> **前提:** [Command パッケージ](README.md)

## はじめに {#getting-started}

### コマンドを発見対象にするには？ {#make-discoverable}

`Command` で終わるクラスをスキャン対象のアプリパスに置くか、パッケージなら `composer.json` の `extra.switon.commands` に列挙します。

### アクションはどうやって実行エントリになる？ {#actions-runnable}

`Action` で終わる public メソッドを使う。`defaultAction()` が素の `command` 入口です。

### サポート用アクションを隠すには？ {#hide-action}

クラスまたはメソッドに `#[Hidden]` を付けます。

### AI 向けの synopsis を足すには？ {#add-tool-synopsis}

アクションに `#[Tool('...')]`。何をするかに絞って短く書きます。

## メタデータ {#metadata}

### 短いオプション別名は？ {#short-option}

パラメータに `#[ShortOption('x')]`。CLI が短い／長いオプションを解決し、`CommandInspector` はメタデータを出すだけです。

### help の文はどこから？ {#help-text}

クラス docblock とアクション docblock の**最初の文**が人向け要約。`@param` がパラメータ help を埋めます。

### なぜ `list` にコマンドが無い？ {#missing-from-list}

多くは未発見か隠れているか。クラス名、スキャンパス、`#[Hidden]` を先に確認します。

### 発見がコマンドを登録する？ {#discover-register}

いいえ。`CommandDiscoveryInterface::discover()` は名前→クラスのキャッシュマップを返すだけです。

### composer extra が壊れても組み込みコマンドが出るのはなぜ？ {#builtin-fallback}

`CommandDiscovery` は `composer-extra` のデータが欠けても読めなくても、`list`・`help`・`completion` を発見可能に保ちます。

## 次のステップ {#next}

- **コマンド執筆の全体像:** [README](README.md)
- **設計境界:** [設計の狙い](design.md)
- **コマンド実行と help 出力:** [CLI パッケージ](/ja-JP/cli/)
