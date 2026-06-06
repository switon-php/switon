---
sidebar_label: Beacon パッケージ
description: Switon Beacon：アプリ向け AI スキル、標準への入口、CLI ディスカバリ。
---

# Beacon パッケージ {#beacon-package}

`switon/beacon` は Switon 向けに **アプリ開発者が Composer で入れる** 一式です。AI 向けルールとスキル、標準ドキュメントへの導線、CLI のディスカバリ用ツールを **ひとつのパッケージ** にまとめています。

**主な機能:**

- **アプリ向けスキル:** インストール後、再利用可能なスキルは `vendor/switon/beacon/skills` に配置されます。
- **標準へのポインタ:** `vendor/switon/beacon/standards` が、エージェントをアプリルートの `AGENTS.md` へ誘導するためのブリッジになります。
- **Composer で配布:** 通常のパッケージ更新と同じ手順でルールとスキルを更新できます。
- **Switon 診断:** CLI では `tool:*`、`config:*`、`class:*`、`di:*`、`event:*` などのディスカバリ系コマンドがあります（そのほかも含む）。

## インストール {#installation}

**要件:** PHP 8.3 以上

```bash
composer require --dev switon/beacon
```

## クイックスタート {#quick-start}

Beacon をインストールしたら、エージェント／IDE のブートストラップを **vendor 内のインストール済みパッケージ** を指すようにします。

```bash
bash bin/console tool:list
bash bin/console tool:entry event
bash bin/console config:check switon.yml config
```

- スキルは `vendor/switon/beacon/skills` を読みます。
- `vendor/switon/beacon/standards` で、アプリルートの `AGENTS.md` に載せたルールへつなぎます。
- モノレポの `skills/` ツリーにある **フレームワーク開発用** スキルは、インストールされる Beacon パッケージの **外** にあります。
- `tool:list` は `#[Tool]` の短い要約だけを表示します。
- `tool:commands` は CLI コマンド面の全体を表示します。

## 用語 {#terms}

- **skills:** パッケージ同梱のエージェント向けスキル。
- **standards:** アプリルートの `AGENTS.md` を指すブートストラップ用ポインタ。
- **tool:\*** アプリの CLI 面を JSON で洗い出すコマンド群。
- **AGENTS.md:** 通常、アプリを Beacon に接続するときに編集するファイル。

## 構成・読み込み {#configuration}

Beacon は **`switon.yml` ではなく**、エージェント／IDE のブートストラップから読み込みます。

- アプリルートの `AGENTS.md`
- ローカルのエージェント設定や IDE 設定
- ツールチェーンでの `vendor` パス解決

インストール後は `bash bin/console tool:commands` でコマンド一覧の全体を確認できます。

ツール実行は `@runtime/beacon/tool.log` に **マスク済み** の監査ログとして残ります。

## 注意点 {#keep-in-mind}

- モノレポの `skills/` を断片コピーするのではなく、**インストール済み** の `vendor/switon/beacon/` を指してください。
- Beacon は **AI ブートストラップ用バンドル** と捉え、汎用の PHP ランタイムライブラリではありません。
- **アプリごとに一度** インストールすれば足ります。手で二重に vendor 化しないでください。

## 次のステップ {#next}

- **FAQ:** [FAQ](faq.md)
- **パッケージ境界:** [設計の狙い](design.md)
