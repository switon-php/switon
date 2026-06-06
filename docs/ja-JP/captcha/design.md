---
sidebar_label: Captcha の設計方針
description: セッションレス＋ストア、試行回数の意味、Imagick/GD。
---

# 設計の狙い {#design-rationale}

Switon の CAPTCHA は **チャレンジ発行と検証** を、単一 PHP セッションや描画スタックに縛らない **セッションレスだがストア支援** の境界として揃えます。

## 参照 {#see-also}

- [Captcha パッケージ](README.md) — 使い方・設定・運用判断
- [FAQ](faq.md) — よくある質問とトラブルシュート

---

## 抱えていた問題 {#core-runtime-assumptions}

Captcha はだいたい次のように崩れがちです。

- SPA/API と両立しにくい **セッションだけの秘密**
- コントローラ入口ごとに違う **検証ルール**
- HTTP アクションに散らばる **レンダラ依存**
- **TTL／リトライの意味** がバラバラ

`switon/captcha` は **`create()`** と **`verify()`** を揃えて保ちます。

## 主な狙い {#primary-challenge-contract}

スティッキーセッションなしに、API・SPA・古典的フォーム向け **ひとつの公開契約** を出します。

- **利点:** ワーカーが Redis（または別ストア）の真実を共有できる。
- **代償:** 共有データストアの用意が必須。
- **向く場面:** ログイン、サインアップ、パスワードリセット、荒らされる POST など。

## 共有ストア {#shared-session-affinity}

クライアントへ **`key`** を返し、答えは **`StoreInterface`** に載せるとセッションアフィニティ問題が消えます。

得られるもの:

- 状態の少ない PHP ワーカーが発行済みチャレンジを検証できる。
- SPA と JSON API が同じガードを共有できる。
- 表面は **`create()`** ↔ **`verify()`** で明快。

セッションレス UX はネットワーク越しの状態に依存する — Redis は偶然ではなく意図的です。

## 試行回数の意味 {#attempt-semantics-deliberate}

**不一致も成功も**、枯れるまで **試行を消費**します。

総当たりを高くつかせるため、`too_frequent`、`expired`、キー欠けは **`verify()`** が早々に打ち切るのでデクリメントしません。

エントリは TTL が切れるまで残り、遅い送信は **`CaptchaNotFoundException`** できれいに返せます。

## レンダラ戦略 {#rendering-api-output}

ひとつの `CaptchaInterface` が Imagick と GD の差を隠します。

- **Imagick** を優先
- なければ **GD**
- どちらも無ければハードエラー

ピクセル完全一致より安定 API を優先。**`distort`** は Imagick 専用で GD はノイズ／サイズ周りのノブを使います。

## セキュリティの重ね方 {#security-layered-singular}

防御の積み上げ:

1. **`min_interval`**
2. **TTL**
3. **試行上限**
4. **描画エントロピー**
5. **ASCII `charset` 既定**

段階的に調整する — すべて最大にするとボットより人間が先に詰まります。
