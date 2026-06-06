---
sidebar_label: Event FAQ
description: Event の dispatch、リスナー発見、ログ、StopFlow。
---

# Event FAQ {#event-faq}

セットアップ・発見・イベントフローへの短い回答です。

> **前提:** [Event パッケージ](README.md)

## はじめに {#getting-started}

### ひとつ作って dispatch するには？ {#create-and-dispatch}

イベントクラスを定義し、`EventDispatcherInterface` を注入して `dispatch()`。[クイックスタート](README.md#quick-start) を参照。

### リスナーは同期？ {#listeners-synchronous}

はい。`dispatch()` は現在のプロセスとコールスタックでリスナーを実行する。バックグラウンド処理が要るなら dispatcher の外に明示的な非同期境界を作る。

### `dispatch()` はデータを返せる？ {#return-value}

いいえ。同じイベントオブジェクトを返すだけで、リスナーの戻り値は返さない。結果が要るならサービスやクエリオブジェクトを使う。

## 設定 {#configuration}

### リスナーはどこから発見される？ {#discovery}

既定では `app/Listener/*Listener.php` と `app/Areas/*/Listener/*Listener.php`。パッケージは `extra.switon.listeners` の Composer メタデータからも来うる。

### 登録済みリスナーの確認は？ {#inspect-listeners}

`bash bin/console event:listeners`、または `bash bin/console event:listeners --filter '<event-fqcn>'`。これらのコマンドは Event パッケージ本体ではなく Beacon 由来。

### 現在のオプションは？ {#discover-options}

`config:options 'Switon\Eventing\ListenerDiscoveryInterface'` と `config:options 'Switon\Eventing\EventLoggerInterface'`。

## ログ {#logging}

### 自動イベントログの制御は？ {#control-logging}

スキップ: `EventSilent`。重大度: `#[EventLevel]`。カスタム: `EventLogInterface`。

### `ObservabilityProbe` はいつ？ {#observability-probe}

主役がログ・トレース・メトリクスのリスナークラスに。業務リスナー数から分離される。

### 後続リスナーを止めるには？ {#stop-flow}

意図的に止めたいときはリスナーから `Switon\Core\StopFlow` を投げる。

## トラブルシュート {#troubleshooting}

### リスナーが動かない {#listener-not-firing}

`public`、`#[EventListener]`、型付きパラメータ1つ、発見パス上のクラス（または別途登録）を確認。型なし・intersection 型は登録時に fail-fast する。

### 親型でサブクラスイベントを拾える？ {#parent-types}

いいえ。複数クラスをひとつのリスナーで扱うなら union か `object`。

### プロバイダが配線されていないと？ {#no-provider}

`dispatch()` は元のイベントをそのまま返し、それ以外は何もしない。
