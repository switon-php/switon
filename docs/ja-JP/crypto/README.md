---
sidebar_label: Crypto パッケージ
description: switon/crypto：可逆暗号、派生キー、検証、キーローテーション。
---

# Crypto パッケージ {#crypto-package}

`switon/crypto` は Switon アプリ向けに、**可逆暗号**、決定的な派生キー、構造化プレーンテキストの検証、キーローテーションを標準化します。

**主な機能:**

- **ひとつの暗号契約:** `Switon\Crypto\CipherInterface` でアプリコードを単一の可逆境界に揃える。
- **キーモード:** `mode` が `secret` からの派生か、小文字 hex の生キー扱いかを決める。
- **プレーンテキスト検証:** `Switon\Crypto\ValidatorInterface` が復号後テキストを組み込みルールまたは callable で検査。
- **ローテーション:** `Switon\Crypto\RotatingCipher` が旧暗号文を読みつつ新規書き込みは現行 cipher を使う。
- **キー生成:** `bash bin/console key:generate` で新しいアプリ秘密用のキーを出力。

---

## インストール {#installation}

**要件:** PHP 8.3 以上、`ext-openssl`、`ext-mbstring`

```bash
composer require switon/crypto
```

---

## クイックスタート {#quick-start}

- 新規アプリは `derived` モードが最も安全な出発点。
- マスター秘密は `switon.yml` に置く。
- `CipherInterface` を注入する。

```yaml
# switon.yml
Switon\Crypto\CipherInterface:
  secret: '<master-secret>'
  mode: derived
```

- 旧 cipher からローテーションするときは `RotatingCipher` を `newCipher` と `legacyCipher` スロットでバインド。

```yaml
# switon.yml
Switon\Crypto\CipherInterface:
  class: Switon\Crypto\RotatingCipher
  newCipher:
    class: Switon\Crypto\Cipher
    secret: '<current-secret>'
    mode: derived
  legacyCipher:
    class: Switon\Crypto\Cipher
    secret: '<legacy-secret>'
    mode: raw
```

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Crypto\CipherInterface;

final class UserSecretService
{
    #[Autowired] protected CipherInterface $cipher;

    public function encryptProfile(int $userId, array $profile): string
    {
        return $this->cipher->encrypt(
            json_encode($profile, JSON_THROW_ON_ERROR),
            "user:{$userId}"
        );
    }

    public function decryptProfile(int $userId, string $payload): array
    {
        // 'json' validates the decrypted plaintext before it is returned.
        $json = $this->cipher->decrypt($payload, "user:{$userId}", 'json');

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
```

- プレーンテキストの形が分かっているときは検証ルールを使う。
- 暗号文はバイナリ。保存や転送が生バイトを扱えないときだけ base64 する。

---

## コア概念 {#core-concepts}

### キーモード {#key-modes}

`Switon\Crypto\Cipher` はキーの解釈を三つサポートする。

- `auto` — 有効な小文字 hex ならそのまま生キー、それ以外は `secret` から派生。
- `derived` — 常に `secret` と関連キー素材からバイナリキーを派生。
- `raw` — 本当の小文字 hex キーのみ受け付け、派生をスキップ。

- 新規データは `derived`。
- 入力が本当に小文字 hex のキーバイト列のときだけ `raw`。
- `auto` は小文字 hex に一致すれば raw、それ以外は `secret` から派生。

### 検証 {#validation}

`decrypt()` は返す前にプレーンテキストを検証できる。

- 組み込み: `ascii`、`utf8`、`json`、`numeric`、`base64`、`uuid`、`email`。
- ルールの前に `?` を付けると空文字列を許可。
- 組み込みに合わない形は callable を渡す。
- `null` は `Switon\Crypto\Cipher` のみ。`RotatingCipher::decrypt()` はルール必須。

### キーローテーション {#key-rotation}

`Switon\Crypto\RotatingCipher` は旧暗号文がまだ残る移行期間向け。

- 新規書き込みは現行 cipher 経由
- 復号は新 cipher を先に試す
- レガシーフォールバックは **非 null の検証ルール** を渡したときだけ
- 新経路の任意の `Switon\Crypto\Exception` がレガシーフォールバックを起こしうる
- 暗号以外の例外はそのまま伝播
- レガシーフォールバックは検証ルールを再適用しない

### 派生キー {#derived-keys}

他コンポーネントが安定したキー素材を要るときは `getDerivedKey()`。

- 出力は小文字 hex
- 同じ型とビット長なら常に同じキー
- `bits` は 8 の正の倍数

### キー生成 {#key-generation}

- `bash bin/console key:generate` が新しいアプリ秘密用のランダムキーを出力。
- 既定出力は `RandomInterface::chars(32)` の文字列（生バイトではない）。
- `--length` で文字数、`--lowercase` で小文字出力。

---

## 設定 {#configuration}

- まず `secret` ひとつ、`mode` ひとつ、既定の cipher メソッド。
- 既存データストアや OpenSSL プロファイルに合わせるときだけ `method` や `algo` を変える。

- `derived` では `secret` 必須。
- `method` 既定は `AES-128-CBC`。
- `algo` 既定は `sha512`。
- `mode` 既定は `auto`。
- 組み込みルールが合わないときだけ `ValidatorInterface` を差し替え。
- `RotatingCipher` の `getDerivedKey()` と復号の第一試行は `newCipher`。
- `legacyCipher` はフォールバック読み取りのみ。

`switon.yml` で設定:

```yaml
# switon.yml
Switon\Crypto\CipherInterface:
  secret: '<master-secret>'
  mode: derived
```

---

## オプションの確認 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Crypto\CipherInterface'
```

---

## 注意点 {#keep-in-mind}

- 通常のアプリデータは `derived`。
- `raw` は本当の小文字 hex キーだけ。
- `auto` は有効な小文字 hex を raw、それ以外を `secret` から派生。

---

## 次のステップ {#next}

- **エラー・ルール・ローテーション:** [FAQ](faq.md)
- **トレードオフ:** [設計の狙い](design.md)
