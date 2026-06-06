---
sidebar_label: JWT パッケージ
description: switon/jwt：HMAC JWT の発行・検証、kid、単一信頼境界。
---

# JWT パッケージ

`switon/jwt` はアプリ所有のトークン境界向けに HMAC JWT を発行・検証します。

**主な機能:**

- **単一信頼境界:** `decode()` が構造・ヘッダ・署名・時間クレームを検証してからクレームを業務ロジックへ
- **HMAC 署名:** `HS256`・`HS384`・`HS512`
- **トークンファミリルーティング:** 任意のヘッダ kid で `jwt:{kid}` に分離
- **DI 既定:** 通常の Switon アプリで `JwtInterface` → `Jwt`
- **型付き失敗:** 不正・期限・not-before・署名・アルゴリズム・kid 境界を個別に

## インストール {#installation}

**要件:** PHP 8.3 以上、`switon/crypto`

```bash
composer require switon/jwt
```

## クイックスタート {#quick-start}

`JwtInterface` を注入し、`encode()` で発行、`decode()` で検証。

```php
use Switon\Core\Attribute\Autowired;
use Switon\Jwt\JwtInterface;

class AuthService
{
    #[Autowired] protected JwtInterface $jwt;

    public function issueForUser(int $userId): string
    {
        return $this->jwt->encode(['user_id' => $userId], 3600);
    }

    public function readToken(string $token): array
    {
        return $this->jwt->decode($token);
    }
}
```

`encode()` は `iat` と `exp` を上書き: 常に `iat` を設定、`ttl > 0` で `exp` を付与、`ttl <= 0` で `exp` を除去。`decode()` は検証成功後にのみクレームを返す。

トークンファミリごとに別キーが要るときは `kid`（JWT ヘッダの key ID）:

```php
$token = $this->jwt->encode(['user_id' => 123], 3600, 'tenant:acme:v1');
$claims = $this->jwt->decode($token, 'tenant:acme:v1');
```

## コア概念 {#core-concepts}

### 信頼境界 {#trust-boundary}

`decode()` が信頼境界。トークン構造・ヘッダ `alg`・任意 `typ`・ペイロード構造・署名・`exp` / `nbf` を確認してからクレームを返す。

### 時間クレーム {#time-claims}

`encode()` は `iat` を自動設定。`ttl > 0` で `exp`、`ttl <= 0` で `exp` 除去。`leeway` で時計ずれ時の `exp` / `nbf` を緩和。

### トークンファミリルーティング {#token-family-routing}

ヘッダ kid なし — 設定 `key` または `jwt` から導出した既定キー。`kid` あり — `jwt:{kid}` を導出し、ヘッダ `kid` が呼び出し側境界と一致することを期待。
`decode($token, null)` でもトークンヘッダに `kid` があれば、そのヘッダがキー解決を駆動する。

### 失敗 {#failures}

空トークン `NoCredentialException`、形状 `MalformedException`、ヘッダ `kid` 不一致 `KidMismatchException`、改ざん・誤キー `SignatureException`、時間 `ExpiredException` / `NotBeforeException`。

## 設定 {#configuration}

```yaml
Switon\Jwt\JwtInterface:
  alg: HS256
  key: null
  leeway: 0
```

`key` は小文字 hex・文字数偶数。`key: null` は導出既定キー。hex 長はアルゴリズム強度と厳密一致不要。

## オプションの確認 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Jwt\JwtInterface'
```

## 注意点 {#keep-in-mind}

- `decode()` は署名のみの verify 経路ではない。
- `kid` はトークンファミリ用でペイロードデータ用ではない。
- `jwt_encode()` / `jwt_decode()` は軽量ヘルパー用；サービスは `JwtInterface` を注入。
- 先に JWT を検証し、その後クレームに認可・業務ルールを適用。
- ヘルパーは起動済みコンテナを前提（`App::get()` から `JwtInterface` を解決）。

## 次のステップ {#next}

- **短い回答:** [FAQ](faq.md)
- **境界の選択理由:** [設計の狙い](design.md)
