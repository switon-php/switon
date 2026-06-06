---
sidebar_label: Core の設計方針
description: 薄い共有語彙層：契約優先、属性、Context 分離、Kernel との境界。
---

# 設計の狙い {#design-rationale}

Core は Switon の **共有語彙を薄く安定** させる層です。

## 参照 {#see-also}

- [Core パッケージ](README.md) — 契約・慣習・学習経路
- [FAQ](faq.md) — エッジケースと初回の混乱
- [Kernel パッケージ](/en/kernel/) — 実行可能な起動順序を所有
- [Context パッケージ](/ja-JP/context/) — 既定の `ContextManager` 実装

---

## 共有言語 {#shared-language}

フレームワーク各パッケージは、コンテナ、アプリメタデータ、シーン、パス、コンテキスト、エラーについて **同じ言葉** が要る。Core はその語彙を安定させ、他に属する機能ドメインは吸収しない。

## 契約優先 {#contracts-first}

`Switon\Core\*` 下のインターフェースがサポートされる形を定義する。具象クラスは同梱の既定であり、唯一の実装ではない。テストが単純になり、専門パッケージの余地が残る。
Core は契約を文書化し、ランタイム挙動は兄弟パッケージが担う（ページで Core 自身が強制すると明記されている場合を除く）。

## DSL より属性 {#attributes-over-dsls}

`#[Autowired]`、`#[Scene]`、`#[ResourceAlias]`、`#[Communal]` は、説明対象のコードのそばにフレームワークメタデータを置く。同じ情報を別 DSL にすると、読み取りとリファクタが難しくなりがち。

## 共有状態の代わりに Context {#context-instead-of-shared-state}

可変なリクエスト／接続データは、共有サービスや static ではなくコンテキストオブジェクトへ。Core は `ContextAware`、`ContextManagerInterface`、`ContextIsolated`、`ContextConnScoped` でその分割を標準化する。
`ContextConnScoped` は記述用であり、`ContextManager` の分岐規則ではない。

## ブートストラップは Kernel {#bootstrap-kernel}

`ServiceProviderInterface` と `ContainerInterface` は Core の契約だが、**順序付け** は `Switon\Kernel` が所有する。Core は役割に名前を付け、Kernel が env 読み込み、プロバイダ発見、シーン適用、自動注入、起動順を担う。
