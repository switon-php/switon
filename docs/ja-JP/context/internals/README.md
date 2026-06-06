---
sidebar_label: Context 内部
description: ContextManager とコルーチンローカル状態の実装背景（日常 API 以外）。
---

# Context 内部

`ContextManager` とコルーチンローカル状態を、**日常の API ではなく実装の狙い** から辿るドキュメントです。

## いつ読むか {#scope}

次を理解したいときに使います。

- フレームワークレベルの状態管理に `Coroutine::getContext()` だけでは足りない理由
- Switon が格納と発見を `ContextManager` に集約する理由
- 継承・分離・ランタイム分岐が、具体的な失敗モードからどう生まれたか

---

## 利用可能なノート {#available-notes}

- [進化のウォークスルー](evolution.md) — 素朴なコルーチンローカル格納から本番設計まで、段階的な経緯

---

## 次のステップ {#next}

- [Context パッケージ](../README.md) — 公開 API、分離モード、よくある落とし穴
- [設計の狙い](../design.md) — 安定した格納とライフサイクル
- [Context 内部](README.md) — このフォルダの読み方とノート一覧
