---
sidebar_label: Captcha FAQ
description: Captcha のフォーム連携、設定、例外、試行回数、トラブルシュート。
---

# Captcha FAQ {#captcha-faq}

よくある質問とトラブルシュートです。

> **前提:** [Captcha パッケージ](README.md)

## はじめに {#getting-started}

### フォームに CAPTCHA を足すには？ {#add-captcha}

`Switon\Captcha\CaptchaInterface` を注入し、クライアントへ `create()` を返し、送信アクションを `Switon\Captcha\Attribute\CaptchaRequired` で保護します。
[クイックスタート](README.md#quick-start) を参照。

### `create()` の戻り値は？ {#return-value}

`['key' => '<不透明>', 'blob' => 'data:image/jpeg;base64,...']`。クライアントは `blob` を描画し、`key` を `captcha_key` として POST します。
[チャレンジの流れ](README.md#challenge-flow) を参照。

### `captcha_key` と `captcha_code` の両方が必要？ {#request-fields}

はい。`CaptchaRequired` は trim 後の両フィールドを読みます。欠けや空は多くの場合 HTTP のクライアントエラーではなく `CaptchaNotFoundException`。
検証の前に欠けた proof を表現したいときは `CaptchaProof` をバインドし `CaptchaProofRequiredException` を使います。

## 設定 {#configuration}

### どこで設定する？ {#where-to-configure}

`Switon\Captcha\CaptchaInterface` は `switon.yml` に書きます。別バックエンドや Redis プレフィックスを締めるときだけ `Switon\Captcha\StoreInterface` に触れます。
[設定](README.md#configuration) を参照。

### 幅・高さ・TTL を変えたい {#size-and-ttl}

必要に応じて `create($width = 120, $height = 40, $ttl = 300, ?string $tag = null)` で上書きするか、そのシグネチャに合わせて既定値を設定で変えます。

### カスタムフォントは？ {#fonts}

`CaptchaInterface.fonts` にフォントパスを列挙します。実行時にすべての PHP ホストから読める必要があります。

### 永続化実装を差し替えられる？ {#different-store}

`Switon\Captcha\StoreInterface` を実装します。方針が許す限り Redis が現実的な既定です。

## セキュリティとエラー {#security-and-errors}

### どの例外を捕まえる？ {#which-exceptions}

人為的失敗をひとつの UX にまとめたいときは `InvalidCaptchaException`。

シグネチャで typed `CaptchaProof` が絡むときは `CaptchaProofRequiredException` を別途。

[注意点](README.md#keep-in-mind) を参照。

### 失敗が HTTP 200 で返ることがあるのはなぜ？ {#failure-http-200}

CAPTCHA の失敗はあえてバリデーション風ペイロード（`captcha.invalid` など）に寄せています。API が HTTP で状態を出したければ例外を **`4xx`** にマップしてください。

### 誤答で試行は減る？ {#attempt-consumption}

はい — **不一致** と **検証成功** の両方で消費されます。

`too_frequent`、`expired`、`not_found` は **`verify()`** が比較パイプラインまで進まないためデクリメントしません。

### 難易度を安全に上げたい {#make-it-harder}

まず **`min_interval`** と **`attempts`**、メトリクスで濫用が見えてから視覚的なゆらぎを調整、**`charset`** はシングルバイト ASCII を維持、**`distort`** は Imagick にだけ効くことを忘れない。

[設定](README.md#configuration) を参照。

### どのレンダラを前提にすべき？ {#which-backend}

既定の自動選択（**Imagick 優先、GD フォールバック**）は意図的です。両方入れると品質と劣化時の耐性がよくなります。

## トラブルシュート {#troubleshooting}

### 検証が通らない {#not-verifying}

例外のサブタイプを見る: データストア／接続（`not_found` など）、間隔スロットル（`too_frequent`）、TTL 切れ、試行枯渇（`CaptchaAttemptsExhaustedException`）、不一致（`CaptchaMismatchException`）。

### 画像が出ない {#image-not-loading}

`blob` をそのまま `<img src>` に代入する。Imagick／GD とフォントディレクトリの読み取り、**`CaptchaCreating`** に紐づくコンテナログを確認する。

### いつも `too_frequent` になる {#too-frequent}

送信が `min_interval` より速い。自動化を遅らせるか閾値を下げる。

[注意点](README.md#keep-in-mind) を参照。

### 自動テストの考え方 {#testing}

`StoreInterface` を二重化し乱数をスタブし、必要なら **`min_interval` → 0**、レンダラ差分をわざと見ないなら粗いラスタ指標で十分なことが多い。
