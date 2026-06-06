---
sidebar_label: DI の設計方針
description: 依存関係中心の配線、インターフェース優先、プロセススコープ。
---

# 設計の狙い {#design-rationale}

DI は、アプリコードをコンテナ中心ではなく **依存関係中心** に保つ層です。

## 参照 {#see-also}

- [DI パッケージ](README.md) — 配線・自動配線・コンテナ境界
- [FAQ](faq.md) — よくある配線問題
- [Autowired](autowired.md) — プロパティ注入の詳細
- [Factory](factory.md) — 名前付きインスタンスとスロット選択

---

## 共有依存の形 {#shared-dependency-shape}

モジュールごとに依存スタイルを発明し始めるのが最初のドリフト。DI は型付きプロパティ、契約優先解決、共有と新規の分離で Switon コードを揃える。

## インターフェース優先 {#interface-first}

同一名前空間の `XxxInterface` → `Xxx` を通常経路とする。よくあるケースは登録不要で、明示バインドは本当に要るときだけ。

## プロパティ注入優先 {#property-injection-first}

`#[Autowired]` は利用クラスのそばに依存宣言を置き、コンストラクタより前に走る。経路は短いが、素の `new` は注入経路ではない。

## 業務コードに名前付き ID を置かない {#named-slots}

複数インスタンスが要る契約にはプロパティ名スロット + `Factory` 設定。安定ケースの選択は設定に、名前がデータ由来のときだけ `NamedLookupInterface`。

## コンテナ状態はプロセススコープ {#container-state}

`get()` は共有インスタンスを返すので、コンテナはリクエストスコープの状態庫ではない。可変状態は [Context パッケージ](/ja-JP/context/) の `ContextAware` / `ContextManagerInterface` へ。

## ブートストラップはフレームワーク側 {#bootstrap-belongs-to-the-framework}

DI は `ContainerInterface`、`InjectorInterface`、`ServiceProvider` を公開するが、起動順序は上位に置く。DI は配線規則に集中し、起動シーケンスは持たない。
