---
sidebar_label: Beacon FAQ
description: Beacon のインストール場所、更新、tool コマンド、トラブルシュート。
---

# Beacon FAQ {#beacon-faq}

インストールとパッケージ境界に関する短い回答です。

## はじめに {#getting-started}

### Beacon は何のため？ {#beacon}

Switon アプリケーション向けに、Composer で入れられる **アプリ向け** の AI ルールとスキルのバンドルです。

### フレームワーク開発用の `skills/` と同じ？ {#root-skills}

いいえ。Beacon は **`composer install` 後の vendor に置かれる** アプリ向けレイヤーです。別にあるフレームワーク開発用 `skills/` は、フレームワーク本体へのコントリビューション向けです。

### `switon.yml` で読み込む？ {#switonyml}

通常はいいえ。`AGENTS.md` やローカルのエージェント設定から Beacon を指します。

## パッケージの使い方 {#package-use}

### インストール後、スキルはどこ？ {#where-skills-live}

`vendor/switon/beacon/skills` です。`vendor/switon/beacon/standards` が、アプリルートの `AGENTS.md` への行き方をエージェントに伝えます。

### 更新は？ {#how-to-update}

ほかの Composer 依存と同じように更新します。

### `tool:commands` は何を見せる？ {#tool-commands}

`code:*`、`path:*`、`packages:*`、`composer-extra:*` などを含む **完全な** CLI コマンド面です。`tool:list` は `#[Tool]` の短い要約だけです。

### ツール呼び出しはログに残る？ {#tool-invocation-log}

はい。`ToolInvoking` イベントから `@runtime/beacon/tool.log` に **マスク済み** の監査ログが出ます。

## トラブルシュート {#troubleshooting}

### エージェントが Beacon のルールを見ない {#agent-not-seeing}

`switon/beacon` がインストールされているか、ディスク上に `vendor/switon/beacon/` があるか、ブートストラップがそのパスを解決しているかを確認してください。

### `vendor/` 内の Beacon を直接編集していい？ {#edit-vendor}

いいえ。アプリ固有のルールは **アプリ側が所有する** ファイルに置いてください。Beacon 本体に変更があったら Composer で依存を上げるか上流へコントリビュートし、`vendor/` の編集を長期運用にしないでください。
