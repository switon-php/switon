---
sidebar_label: Executor の設計方針
description: シリアルとコルーチンで共通の Runner 境界、トランスポートは外側。
---

# 設計の狙い {#design-rationale}

`Executor` はシリアルとコルーチンランタイムで **ひとつの Runner 境界** を標準化する。タスク identity・バッチ結果・ライフサイクル可視性をひとつの契約にまとめ、トランスポート・スケジューリング・監視は外に置く。

## 参照 {#see-also}

- [Executor パッケージ](README.md) — 使い方・ランタイム・例外
- [FAQ](faq.md) — タスク id・ランタイム・トラブルシュート

---

## なぜあるか {#why-this-exists}

同じ処理を別トリガーから走らせるとき、トリガーごとに実行意味論がばらけがち:

- クラス名でルーティング
- 部分失敗の扱いが違う
- 結果ハンドルがない
- トリガー端だけ観測し、実際の run 境界は見えない

`Executor` はまずその漂流を抑える。

## Switon が標準化するもの {#what-switon-standardizes}

次の入口を収束させる:

- `ExecutorInterface`
- `FutureInterface`
- `Switon\Executor\Event\RunnerStarting`
- `Switon\Executor\Event\RunnerFinished`

ランタイムが変わっても実行挙動を安定させる。

## 外に置くもの {#what-stays-outside}

`Executor` は永続キュー・リトライ・プロセス監視・トランスポート方針を持たない。

それらはアプリか他パッケージ。フレームワークは run 境界と呼び出し側 API だけを標準化する。

## タスク分割の理由 {#why-the-task-split-exists}

`:` より前で Runner サービスを解決。文字列全体が `submit()` のゲートキー。

ひとつの Runner サービスで複数の名前付きタスクストリームを支え、ストリームごとにサービス binding を増やさない。

## バッチモードが二つある理由 {#why-there-are-two-batch-modes}

`invokeAll()` — 入力スロットごとに Future を1つ。全結果が見える。

`invokeAny()` — 最初の成功向け。失敗試行はスキップ扱いで終端エラーにしない。

## イベントが run 境界を包む理由 {#why-events-wrap-the-run-boundary}

可観測性は外側トリガーだけでなく `RunnerInterface::run()` の周りに置く。

イベントペアで他パッケージに実行レベルのライフサイクルストリームを1本渡し、run タイミングロジックの重複を避ける。イベント dispatch の詳細は [Event パッケージ](/ja-JP/event/) を参照。
