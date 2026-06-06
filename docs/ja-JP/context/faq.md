---
sidebar_label: Context FAQ
description: いつ Context を使うか、Core の ContextManager、ContextIsolated、resetContexts。
---

# Context FAQ {#context-faq}

**よくある質問と短い回答です。**

> **前提:** [Context パッケージ](README.md)

## はじめに {#getting-started}

### いつ Context を使う？ {#use-context}

長寿命サービスが複数メソッドにまたがる **可変状態** を持ち、その状態が **現在のリクエストまたはコルーチンスコープ** に追従すべきとき。一回限りの受け渡しだけならリクエスト属性の方がだいたい単純。
[Context パッケージ](README.md) と [HTTP Request](/en/http/request) を参照。

### なぜ `Switon\Core\ContextManagerInterface` を注入する？ {#inject-core-interface}

アプリコードでは Core 側契約を注入する。起動時に context パッケージが既定実装を差し込むので、サービスはパッケージ具体クラスから切り離せる。
[設定](README.md#configuration) を参照。

### `getContext()` の戻り型には何が要る？ {#context-return-type}

**読み込み可能なコンテキストクラスひとつ** を宣言し、そのクラスは **必須コンストラクタ引数なし** で作れること。不正な宣言は `Switon\Context\Exception\ContextException`。
[コンテキストクラスのルール](README.md#context-class-rules) を参照。

### `ContextIsolated` はいつ？ {#use-contextisolated}

子コルーチンが親と **一時状態やリソース束縛状態** を共有してはいけないとき（トランザクション、スクラッチバッファなど）。付けない限り、子は親コンテキストを継承する。
[マーカー契約](README.md#marker-contracts) を参照。

### `resetContexts()` は何を消す？ {#reset-contexts}

マネージャインスタンス上の **非コルーチンキャッシュだけ**。長寿命ワーカーでは、リクエストをまたいで状態を再利用したくないときは別途リセットが必要。
[注意点](README.md#keep-in-mind) と [設計の狙い](design.md) を参照。
