---
sidebar_label: JWT FAQ
description: トークン境界、キー、例外、認可との関係。
---

# JWT FAQ

トークン境界・キー・失敗への短い回答です。

> **前提:** [JWT パッケージ](README.md)

## はじめに {#getting-started}

### どのインターフェースを注入する？ {#which-interface}

`Switon\Jwt\JwtInterface`。

### `decode()` は何を検証する？ {#decode-validates}

構造・ヘッダ `alg`・任意 `typ`・ペイロード構造・期待 `kid`・署名・数値 `exp` / `nbf` の後にクレームを返す。

期待 `kid` に `null` を渡してもトークンヘッダに `kid` があれば、そのヘッダが導出キーファミリを選ぶ。

### `kid` はいつ使う？ {#kid-usage}

テナント・サービス・ロールアウト版ごとに署名キーを分けたいとき。

### なぜ `verify()` がない？ {#no-verify}

信頼境界を意図的にひとつにしている。クレームが信頼入力になるのは `decode()` のみ。

## 設定 {#configuration}

### JWT の設定は？ {#configure}

`switon.yml` に `Switon\Jwt\JwtInterface` ブロック。よく使うのは `alg`・`key`・`leeway`。

### オプション面の確認は？ {#discover-options}

アプリルートで `bash bin/console config:options 'Switon\Jwt\JwtInterface'`。

### 対応アルゴリズムは？ {#supported-algorithms}

`HS256`・`HS384`・`HS512`。

### `leeway` は？ {#leeway}

`exp` と `nbf` に時計ずれ許容を足す。

## キー {#keys}

### `kid` が null のときは？ {#kid-null}

既定キー方針: 設定 `key`、なければ `jwt` から導出した既定キー。

### `key` の形式は？ {#key-format}

小文字 hex・文字数偶数。長さはアルゴリズム強度と厳密一致不要。

### アプリコードが署名キーを直接読むべき？ {#expose-key}

通常いいえ。`encode()` / `decode()` に留める。下位キー素材が明示的に要るときだけ [Crypto パッケージ](/ja-JP/crypto/) の `CipherInterface` を検討。

## トラブルシュート {#troubleshooting}

### 先に捕まえる例外は？ {#catch-order}

境界で重要なものから。よくある順: `ExpiredException`・`KidMismatchException`・`SignatureException`・`MalformedException`・`NotBeforeException`。

空トークンは `NoCredentialException`。呼び出し期待 `kid` とヘッダ `kid` が食い違うのは `KidMismatchException`。

### デプロイ後に `SignatureException`？ {#signature-after-deploy}

有効キー境界を確認: 設定 `key`・期待 `kid`・crypto マスターキー・別 issuer 境界のトークンか。

### `AlgorithmMismatchException` は？ {#alg-mismatch}

トークンヘッダ `alg` が設定アルゴリズムと一致しない。

### JWT 失敗の HTTP ステータスは？ {#http-status}

JWT 例外は HTTP `401` にマップ。

### JWT と認可の関係は？ {#jwt-and-authz}

先に JWT でトークン検証し、[認可パッケージ](/ja-JP/authz/) で権限を適用。
