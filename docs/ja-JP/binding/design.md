---
sidebar_label: Binding の設計方針
description: 引数の組み立てと callable の実行を分ける理由、型側ルール、typed input。
---

# 設計の狙い {#design-rationale}

`switon/binding` は **引数の値をどう組み立てるか** と **callable をどう実行するか** を分離した規約です。

## 参照 {#see-also}

- [Binding パッケージ](README.md) — 使い方・境界・設定
- [FAQ](faq.md) — よくある質問とトラブルシュート

---

## なぜ必要か {#why-this-exists}

エントリコードは多くの場合、最終的な **引数** を持つ前に **メソッド** を決めます。

**バインディング** が繰り返し決めるのは次の三類です。

- **型側ルール:** **`#[ResolvedBy(...)]`** による構築
- **サービス寄りオブジェクト:** ほかで解決できなければ **コンテナ**
- **スカラー:** **`Switon\Core\InputInterface`**

## Invoker との分離 {#separate-invoker}

- **`switon/binding`:** 解決済み **引数リスト** を作る
- **`switon/invoking`（`InvokerInterface`）:** そのリストで callable を実行する

**利点:** 値の出どころ・ハイドレート・バリデーションと、呼び出しポリシーを分けられる。

## スカラーとオブジェクト {#scalars-objects-separate}

スカラーとオブジェクトは **別々の** 解決経路です。

**利点:** 生入力が明示的なバインディングなしにオブジェクト構築へ漏れにくい。

## 型がルールを持つ {#type-owned-resolution}

構築契約を **型が安定して所有** できるものがあります。

例: **`Page`**

- ルールは **`#[ResolvedBy(...)]`** で型の側に置く
- 呼び出し側はパラメータごとにリゾルバを繰り返さない

## 型付き入力のハイドレート {#input-lives-here}

型付き入力バインディングは HTTP（など）と共有する **再利用可能** な仕組みです。

- トランスポートが **ペイロード** を選ぶ
- バインディングが型付きオブジェクトを **ハイドレートして検証** する

## フック {#why-hooks-exist}

横断的なパラメータ変換は **dispatch のコピペ** で増やしたくありません。

**pre-fill** と **最終調整フック** は、ひとつの具体リゾルバより広い振る舞いのときだけ使います。

## Input の所有権 {#input-interface-outside}

バインディングは `Switon\Core\InputInterface` を **読むだけ** で、トランスポートは定義しません。

HTTP・CLI・独自フロントが **同じバインディング** を共有できるのは、契約が中立だからです。
