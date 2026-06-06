---
sidebar_label: Invoker の設計方針
description: DI callable 経路とガード・インタセプタの分離。
---

# 設計の狙い {#design-rationale}

`switon/invoker` は `Switon\Di\Invoker` の周りにガードとインタセプタを足し、管理対象メソッド呼び出しで DI callable 経路とフック経路を分ける。

---

## 参照 {#see-also}

- [Invoker パッケージ](README.md) — 使い方・境界・設定
- [FAQ](faq.md) — 短い回答とトラブルシュート

## 抱えていた問題 {#core-problem}

dispatch コードはだいたい二つの仕事を混ぜがち:

- トランスポートやビルダーから最終引数リストを組み立てる
- DI がフォールバック解決しつつ、ガードとインタセプタで callable 実行を包む

ひとつのパッケージが両方を持つと境界の説明と再利用が難しくなる。

## 主目的 {#primary-goal}

Invoker は DI callable 実行段階の周りにメソッド単位フックを標準化:

- **利点:** HTTP・CLI・RPC・カスタム dispatch が DI から同じ callable 経路を共有可能
- **利点:** ガードはトランスポート解析ではなく invocation に付く
- **利点:** インタセプタも同様
- **代償:** 引数構築は別パッケージのまま

## 中核の選択 {#core-choices}

- **Callable 境界を先に:** 呼び出し側は invocation 開始前に対象 callable を既に知っている
- **小さな最終段解決:** 明示オーバーライド・既定・型キーオブジェクト・DI フォールバックを一箇所に
- **ガード付き実行:** ガードは管理 invocation のみ、素のメソッド呼び出しではない
- **インタセプト実行:** インタセプタも同様

## トレードオフ {#trade-offs}

- 直接呼び出しは単純だがフックをバイパス
- トランスポート binding は外れるが invocation 経路は reasoning しやすい
- コンストラクタ呼び出しは管理 invocation 対象にしない
