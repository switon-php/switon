---
sidebar_label: Log FAQ
description: 既定宛先、levels、appender、LoggerLogged、テスト。
---

# Log FAQ

セットアップ・設定・よくあるログ問題への短い回答です。

> **前提:** [Log パッケージ](README.md)

## はじめに {#getting-started}

### コードにログを足すには？ {#add-logging}

`Psr\Log\LoggerInterface` を注入し、通常の PSR-3 メソッド。[クイックスタート](README.md#quick-start) を参照。

### 既定の出力先は？ {#default-destination}

既定の provider 配線は受理エントリを `@runtime/logger/{scene}.log` へ。コンテナ環境ではファイルの代わりに `StdoutAppender` でプラットフォームのログコレクタへ。

### アプリ全体で同じロガー？ {#same-logger}

はい。HTTP・CLI・ワーカー・ライブラリでひとつの PSR-3 契約向け。

## 設定 {#configuration}

### ログレベルの変更は？ {#change-level}

`level` でグローバル基準、`levels` でカテゴリ上書き。[設定](README.md#configuration) を参照。

### カテゴリ上書きの仕組みは？ {#category-overrides}

`levels` はカテゴリのプレフィックス一致。最長一致プレフィックスがその呼び出しの **しきい値**。しきい値より詳細なレベルは捨てる。

### カテゴリごとに appender を分けられる？ {#route-by-category}

いいえ。受理エントリは設定された **すべて** の appender に fan-out。ノイズ削減は `levels`、宛先分割ではない。

### ひとつの appender が失敗したら他は？ {#appender-failure}

ロガーは順に呼び、エラーを捕まえない。ファイル appender は書き込み失敗を隔離；カスタム appender がスローすると後続が止まり、コードへ伝播しうる。[出力](README.md#output) を参照。

### `LoggerLogged` リスナーで変えられるものは？ {#logger-logged-boundaries}

appender 前に `LogEntry::extra` へフィールド追加。`level`・`category`・`message` はそのエントリでは固定。リスナーから再度 `LoggerInterface` を呼ばない。イベント dispatch の背景は [Event パッケージ](/ja-JP/event/) を参照。

### 利用可能なオプションの確認は？ {#discover-options}

`bash bin/console config:options 'Psr\Log\LoggerInterface'`。appender 固有なら appender クラスを直接照会。

## 挙動 {#behavior}

### 自分のコードのカテゴリは？ {#predict-category}

**呼び出しサイト** のクラスとメソッドから（例: `App\Service\UserService::register()` → `app.service.user.register`）。`Categorizable` で上書き。`context['exception']` があるときはスロー位置。[カテゴリ](README.md#category) を参照。

### メッセージ整形と例外ログは？ {#format-messages}

文はメッセージ、`{key}` は文の値、throwable は `context['exception']`。`LogEntry` 構築後、補間キーは `message`、他は `extra`、`exception` は `message` 側。[メッセージと context](README.md#message) を参照。

### リクエスト id など共通フィールドは？ {#add-correlation}

`Switon\Logging\Event\LoggerLogged` をリッスンし、appender 前に `LogEntry::extra` へ追加。`level`・`category`・`message` は固定。リスナーから `LoggerInterface` を呼ばない。横断 enrichment に留める。

### テストで `MemoryAppender`？ {#memory-appender}

はい。`MemoryAppender` がテスト向けのメモリ宛先。エントリはリクエストまたはコルーチン context ごとに分離。

## トラブルシュート {#troubleshooting}

### ログが出ない {#logs-missing}

まず宛先と有効レベル。実務では誤った appender 先か `levels` のプレフィックス上書きが多い。

### 一部だけ出る {#partial-logs}

より具体的なカテゴリ上書きがフィルタしている可能性。`levels` の最長プレフィックス（`switon.yml` だけでなく logger binding の既定も）を確認。

### ファイル appender が書けないとき {#file-write-failure}

ファイル appender は別チャンネルで失敗を報告。その失敗経路から `LoggerInterface` で再ログしない。

### ログするコードのテストは？ {#testing}

`MemoryAppender` またはスタブ `LoggerInterface`。
