---
sidebar_label: Executor FAQ
description: タスク id、ランタイムモード、バッチ失敗、submit ゲート。
---

# Executor FAQ {#executor-faq}

タスク id、ランタイムモード、エラー処理への短い回答です。

> **前提:** [Executor パッケージ](README.md)

## はじめに {#getting-started}

### `invoke()` は何をする？ {#invoke-sync}

ひとつのタスクを現在のフローで実行し、Runner 失敗を再スローする。

### `submit()`・`invokeAll()`・`invokeAny()` の使い分けは？ {#which-method}

同じフローで結果が要らない → `submit()`。全結果が要る → `invokeAll()`。最初の成功で足りる → `invokeAny()`。

### タスク id に何を渡せる？ {#task-id}

`RunnerInterface` に解決するコンテナ id なら可。既定は Runner の FQCN が分かりやすい。`submit()` バケットを分けるときだけ `:suffix`。

最初の `:` だけが Runner id を分割；以降の `:` はサフィックスに残る。

### `submit()` は常にバックグラウンド？ {#submit-background}

いいえ。コルーチン有効ランタイムのときだけバックグラウンド。シリアルでは現在フロー。

`invokeAll()` と `invokeAny()` は `submit()` ゲートを使わない。

### `invokeAny()` は他タスクをキャンセルする？ {#invoke-any-cancel}

いいえ。最初の成功で返るが、既に開始したコルーチンは走り続ける。

返るのは入力順の最初のタスクではなく、最初に成功した結果。

## ランタイム {#runtime}

### `submit()` の第3引数は？ {#limit-concurrency}

そのタスク文字列の進行中コルーチン submit 上限。`0` 以下で組み込みゲート上限を無効化。

### バッチ失敗の読み方は？ {#batch-failures}

`invokeAll()` で各 `FutureInterface` を確認。失敗タスクの throwable は `error()`。

### `invokeAll()` は最初の失敗で止まる？ {#invoke-all-stop}

いいえ。入力ごとに Future を1つ保持し、失敗は個別に記録。

### どのタスクも成功しないと？ {#invoke-any-null}

`invokeAny()` は `null`。

### スケジュール失敗時は？ {#scheduling-failure}

`submit()` は `TaskSchedulingException`。`invokeAll()` は該当 Future に失敗を記録。`invokeAny()` は別の成功を探し続ける。

Runner がスローしても `RunnerFinished` は発火。`invoke()` の呼び出し元には例外が再スローされる。

## トラブルシュート {#troubleshooting}

### なぜ `TaskRunnerException`？ {#task-runner-exception}

解決されたサービスが `Switon\Core\RunnerInterface` を実装していない。

### なぜ `InvalidTaskException`？ {#invalid-task-exception}

タスク id が空、または `:` より前のプレフィックスが空。
