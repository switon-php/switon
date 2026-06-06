---
sidebar_label: Binding FAQ
description: Binding と Invoker の違い、`#[ResolvedBy]`、スカラー元、テスト。
---

# Binding FAQ {#binding-faq}

セットアップ・設定・よくあるバインディング問題への短い回答です。

> **前提:** [Binding パッケージ](README.md)

## はじめに {#getting-started}

### バインディングと Invoker の違いは？ {#binding-vs-invoker}

**バインディング** が引数リストを組み立てます。**Invoker** が callable を実行します。

### callable がもう分かっているなら binding は要る？ {#already-know-callable}

フレームワークやテストが **入力・ビルダー・コンテナ状態・既定値** から引数まで自動で組み立ててほしいときだけ必要です。

### パラメータ値はどこから？ {#parameter-sources}

- pre-fill フック
- `ArgumentResolvable`
- `#[ResolvedBy]`
- オブジェクトが未解決なら **コンテナ**
- **スカラー** は `InputInterface`
- 既定値とバリデーション

注: **`#[ShortOption]`** は **CLI のオプション解析** 側で、`switon/binding` ではありません。
**`RequestBody`** などは **HTTP パッケージ** の属性ですが、ハイドレートは binding が担当します。

### `InputBinderInterface` はいつ使う？ {#when-inputbinder}

あるリゾルバがすでに **配列ペイロード** を持っていて、**型付き入力のハイドレート** を共通エンジンでやりたいときです。

## ビルダーとリゾルバ {#builders-and-resolvers}

### `#[ResolvedBy]` はいつ？ {#when-resolvedby}

**ひとつの具体型** が **常に同じ手順** で構築されるときです。

### なぜ例は `Page`？ {#why-page}

Switon で **「安定したルールが型の側にある」** のが一番わかりやすい実型だからです。自分の型も同じなら通常は `#[ResolvedBy]` が適しています。

### `Page` パターンを自分の型にどう写す？ {#copy-page-pattern}

クラスに `#[ResolvedBy(...)]` を付け、`ValueResolverInterface` を実装し、リゾルバで必要な入力やサービスを読んで値オブジェクトを返します。

### 型そのものに組み立てさせたいときは？ {#when-argumentresolvable}

構築ルールを **値型そのもの** に置きたいなら **`ArgumentResolvable`** です。

### なぜコンテナからオブジェクトが来る？ {#object-container}

型側ルールにマッチしなかったからです。**インターフェース** 型や **`#[ResolvedBy]` が無い** と **コンテナ** に落ちます。

### スカラーの取り方を広く変えたい {#change-scalar-sourcing}

**アプリ全体でまとめて** 変えたいときだけ **既定のスカラーポリシー** を差し替え／拡張します。

## トラブルシュート {#troubleshooting}

### リゾルバは最終値として `null` を返していい？ {#resolver-null}

既定のリゾルバインターフェースでは **`null` は未解決** です。**`ArgumentResolvable`** は **値型が許すなら** 実際の `null` を返せます。

### pre-fill でインデックス指定で `null` を入れられない {#null-by-index}

インデックスは初期が `null` のため、インデックス経由の `null` が「未設定」と見分けづらいです。**名前で pre-fill** してください。

### 「Too many positional arguments」になる {#too-many-positional}

位置バインディングでは余分な値を拒否します。**最後の未解決スカラーパラメータ** が `array` 型のときだけ例外になります。

### union / intersection 型は？ {#union-intersection}

いいえ。`ArgumentsBinderInterface` は **単純な名前付きパラメータ型** のみです。

### binding が厚いコードはどうテストする？ {#testing}

業務ロジックは **明示的な引数** でメソッドを呼んでテストします。**引数の組み立て** 自体を見たいときだけ binding／HTTP／CLI のスイートで確認します。
