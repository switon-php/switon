---
sidebar_label: Captcha パッケージ
description: Switon captcha：セッションレス、共有ストア、`CaptchaRequired`、verify。
---

# Captcha パッケージ {#captcha-package}

`switon/captcha` は HTTP API や SPA 向けに **ひとつの CAPTCHA フロー** を提供します。ブラウザ側は **セッションに依存せず**、**ストアで状態を共有**するのでどのワーカーでも同じチャレンジを検証できます。

**主な機能:**

- **セッションレスなクライアント経路:** `create()` がチャレンジの **`key`** と画像の **`blob`** を返す。送信時は **`captcha_key`** と **`captcha_code`** を機密アクションと一緒に POST する。
- **アクションガード:** `CaptchaRequired` がコントローラ本体の前に POST フィールドを共有ストアと突き合わせる。
- **共有ストアでの検証:** Redis バックエンドの `StoreInterface` が既定で、PHP セッションのアフィニティに依存しない。
- **ポリシーノブ:** TTL、チェック間の最短間隔、`attempts`、長さ、`charset`、描画オプションなどすべて設定可能。
- **レンダラ選択:** **`imagick` が載っていれば優先**、なければ **`gd`**（どちらも無ければ即失敗）。
- **型付き失敗:** 検証エラーは **`InvalidCaptchaException`** のサブタイプや関連する proof エラーにマップされる。
- **イベント:** **`CaptchaCreating`**、**`CaptchaCreated`**、**`CaptchaVerifying`**、**`CaptchaVerified`**、**`CaptchaFailed`**。

## インストール {#installation}

**要件:** PHP 8.3 以上、共有バックエンド（**既定は Redis**）、**`ext-imagick`** *または* **`ext-gd`**。

```bash
composer require switon/captcha
```

## クイックスタート {#quick-start}

`CaptchaInterface` を注入し、クライアントには **`create()`** を返し、送信エンドポイントには **`#[CaptchaRequired]`** で守ります。

```php
namespace App\Controller;

use Switon\Captcha\CaptchaInterface;
use Switon\Captcha\Attribute\CaptchaRequired;
use Switon\Core\Attribute\Autowired;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('')]
class ContactController
{
    #[Autowired] protected CaptchaInterface $captcha;

    #[GetMapping('/captcha')]
    public function captchaAction(): array
    {
        return $this->captcha->create();
    }

    #[PostMapping('/contact')]
    #[CaptchaRequired]
    public function submitAction(): array
    {
        return ['success' => true];
    }
}
```

クライアントは **`blob`** を **`data:image/jpeg;base64,...`** のデータ URI として扱い、**`key`** を **`captcha_key`** に改名して **`captcha_code`** と共に送ります。

```js
const payload = await fetch('/captcha').then((response) => response.json());

document.querySelector('#captcha-image').src = payload.blob;
formData.set('captcha_key', payload.key);
```

- **`blob`** はそのまま使えるデータ URI です。手でデコードしない。
- **`CaptchaRequired`** の POST 名のサーバ側プレフィックスは **勝手に変えない**。ガード設定と一致させる。

## コア概念 {#core-concepts}

- **`create()`** が **`key`** と **`blob`** を返す。
- **`CaptchaRequired`** が **`captcha_key`** と **`captcha_code`** を読み、DI で解決した **`CaptchaProof`** 経由で **`verify()`** を呼ぶ。

### `CaptchaRequired` {#captcha-required}

証明が欠ける／CAPTCHA が無効なときにアクションを止めたいコントローラで属性を使います。

```php
use Switon\Captcha\Attribute\CaptchaRequired;

#[CaptchaRequired]
public function submitAction(): array
{
    return ['success' => true];
}
```

### `CaptchaProof` {#captcha-proof}

サービスやテストでは **`verify()`** を直接呼びます。

```php
use Switon\Captcha\CaptchaProof;

$this->captcha->verify(CaptchaProof::of($key, $code));
```

- **`CaptchaProof`** は MVC の属性ガードを迂回する統合向けの型コンテナ。
- **`CaptchaRequired`** が POST の **`captcha_key`** / **`captcha_code`** を `CaptchaProof` に対応付ける。
- trim 後に欠けるフィールドは多くの場合 **`CaptchaNotFoundException`**。
- シグネチャで **`CaptchaProof`** をバインドすると、`verify()` の前に **`CaptchaProofRequiredException`** が不正リクエストとして立つことがある。

### 共有ストア {#shared-store}

ブラウザへは **`key`** と **`blob`** だけ行きます。試行カウンタ、TTL、生成コードのハッシュ、発行メタデータは **`Switon\Captcha\StoreInterface`**（既定 Redis）に残ります。スティッキーセッションが不要になり、API と SPA の呼び出し元も揃います。

### チャレンジの流れ {#challenge-flow}

1. クライアントが **`create()`** を呼ぶ。
2. ユーザーが **`captcha_code`** を入力し、見た **`blob`** に対応する **`captcha_key`** を添えて送信する。
3. **`CaptchaRequired` / `CaptchaProof`** の経路が Redis（または別ストア）に対して **`verify()`** を実行する。

## 設定 {#configuration}

既定で試作に十分。ポリシーを強化したいときやマルチテナントのプレフィックス隔離が必要なときに調整します。

```yaml
# switon.yml
Switon\Captcha\CaptchaInterface:
  min_interval: 1.0
  attempts: 2

Switon\Captcha\StoreInterface:
  redis: '#captcha'
  prefix: 'captcha:prod:'
```

### オプションの確認 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Captcha\CaptchaInterface'
bash bin/console config:options 'Switon\Captcha\StoreInterface'
```

## 注意点 {#keep-in-mind}

- ブラウザ経路はセッションレス。**チャレンジキーはフォームと一緒に運び**、答え側の状態は Redis が保持する。
- **`StoreInterface` に繋がるホストなら** **どのワーカーでも** **`verify()`** できる。
- 設定しない限り既定の **`attempts`** は **`1`**。
- 通常の不一致／期限切れ／未検出は **`InvalidCaptchaException`** で拾う。`verify()` の前の proof バインド欠けは **`CaptchaProofRequiredException`**。
- **誤答は設定された上限に従って試行を消費する** — 拒否後は blob を取り直す。
- バックエンド間の描画差は意図的。**`distort`** は Imagick 寄りのビルドにだけ効く。

## 次のステップ {#next}

- **スロットル・バースト対策:** [Throttle パッケージ](/en/throttle/)
- **フィールド検証との関係:** [Validation パッケージ](/en/validation/)
- **Redis 調整:** [Redis パッケージ](/en/redis/)
- **HTTP 契約:** [HTTP パッケージ](/en/http/)
- **運用 FAQ:** [FAQ](faq.md)
- **設計トレードオフ:** [設計の狙い](design.md)
