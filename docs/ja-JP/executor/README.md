---
sidebar_label: Executor パッケージ
description: switon/executor：invoke・submit・invokeAll・invokeAny、Future、Runner ライフサイクル。
---

# Executor パッケージ {#executor-package}

`switon/executor` は、コンテナ登録済み Runner の実行、順序付きバッチ結果、最初の成功選択、タスク単位の同時実行制御を **ひとつの実行 API** にまとめます。

**主な機能:**

- **単一実行 API:** `ExecutorInterface` を注入し、`invoke()`・`submit()`・`invokeAll()`・`invokeAny()` を呼ぶ。
- **同期 invoke:** `invoke()` は現在のフローで実行し、Runner 失敗は再スロー。
- **タスク identity の分離:** `:` より前のセグメントで Runner サービスを解決；`submit()` のゲートキーはタスク文字列全体。
- **順序付き結果:** `invokeAll()` は入力スロットごとに `FutureInterface` を返す。
- **バックグラウンド submit:** コルーチン有効時、`submit()` でバックグラウンド実行を予約できる。
- **実行ライフサイクルイベント:** 各 `RunnerInterface::run()` を `RunnerStarting` / `RunnerFinished` で包む。

---

## インストール {#installation}

**要件:** PHP 8.3 以上；`ext-swoole` でコルーチン有効時の `submit()` と並列バッチ

```bash
composer require switon/executor
```

---

## クイックスタート {#quick-start}

- Runner をひとつ実装する。
- `ExecutorInterface` 経由で呼ぶ。

```php
namespace App\Runner;

use Switon\Core\RunnerInterface;

final class ReportRunner implements RunnerInterface
{
    public function run(mixed $payload): array
    {
        return ['date' => $payload['date'] ?? null];
    }
}
```

```php
namespace App\Service;

use App\Runner\ReportRunner;
use Switon\Core\Attribute\Autowired;
use Switon\Executor\ExecutorInterface;

final class ReportService
{
    #[Autowired] protected ExecutorInterface $executor;

    public function export(): mixed
    {
        $future = $this->executor->invokeAll([
            [ReportRunner::class, ['date' => '2026-01-01']],
        ])[0];

        return $future->isSuccessful() ? $future->result() : null;
    }

    public function submitLater(): void
    {
        $this->executor->submit(ReportRunner::class . ':tenant-a', ['date' => '2026-01-01'], 1);
    }
}
```

- 最初の成功だけ欲しいときは同じタスクパターンで `invokeAny()` に切り替える。

---

## コア概念 {#core-concepts}

### タスク ID {#task-ids}

タスク id は Executor に渡す文字列。

- `ReportRunner::class` — Runner サービスを解決し、同じ文字列が `submit()` のゲートキー。
- `ReportRunner::class . ':tenant-a'` — 同じ Runner を解決するが、`submit()` のゲートキーは文字列全体。
- 最初の `:` だけが Runner id を分割；それ以降の `:` はサフィックスに残る。

- `:` より前は `Switon\Core\RunnerInterface` サービスに解決できること。

### 呼び出しモード {#invocation-modes}

- `invoke()` — 同期経路。現在のフローで実行し、ライフサイクルイベント後に Runner 失敗を再スロー。

- `submit()` — fire-and-forget。コルーチン有効ランタイムではバックグラウンド予約とゲート適用。シリアルでは現在フロー。

- `invokeAll()` — 全タスク完了を待ち、入力順の `list<FutureInterface>` を返す。

- `invokeAny()` — 最初の成功結果、すべて失敗なら `null`。

### Future {#futures}

バッチ結果を個別に見るときは `FutureInterface`。

- 先に `isSuccessful()`；成功タスクの `result()` は `null` になりうる。
- `isSuccessful()` — スローなく Runner が終わったか。
- `result()` — 成功ペイロード。
- `error()` — 失敗タスクの throwable。

### 例外 {#exceptions}

Executor 固有:

- `Switon\Executor\Exception\InvalidTaskException`
- `Switon\Executor\Exception\TaskRunnerException`
- `Switon\Executor\Exception\TaskSchedulingException`

---

## 設定 {#configuration}

- 安定した制御はランタイムモードと `submit()` の引数。

- コルーチン有効ランタイムが `submit()` をバックグラウンドにするか決める。
- `submit()` の第3引数がそのタスク文字列の進行中コルーチン submit 上限。
- ゲートキーはタスク文字列全体；`:` より前で Runner を解決。

- 永続トランスポート・リトライ・プロセス監視はパッケージ外に置く。

---

## 注意点 {#keep-in-mind}

- `invokeAll()` と `invokeAny()` は `submit()` ゲートを使わない。
- `submit()` は結果ハンドルを返さない。
- `invokeAny()` は既に開始したコルーチンタスクをキャンセルしない。
- ひとつの Runner サービスで `submit()` バケットを分けたいときだけ `:suffix` を使う。
- Runner がスローしても `RunnerFinished` は発火し、エラーが付く。

---

## 次のステップ {#next}

- **よくある質問:** [FAQ](faq.md)
- **トレードオフ:** [設計の狙い](design.md)
