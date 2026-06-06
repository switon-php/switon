---
sidebar_label: 認可パッケージ
description: Switon authz：権限コード、#[Authorize]、Lookup、Voter、ランタイム認可。
---

# 認可パッケージ {#authorization-package}

`switon/authz` は **権限コード** に基づく認可モデルを、コントローラとサービスで共通に使えるようにします。アプリケーションのロールは `Switon\Principal\IdentityInterface::getRoles()` から取得します。**認証そのもの**はこのパッケージの外（Identity 層）に置きます。

**主な機能:**

- **アクセス規則:** コントローラやメソッドを `#[Authorize]` で保護できます。
- **単一の認可 API:** サービスでは `AuthorizationInterface` を注入して判定します。
- **Identity の状態:** `Authorize::ANONYMOUS` と `Authorize::AUTHENTICATED` で状態を表します。
- **スーパーユーザー:** `Authorize::SUPERUSER` は組み込みのロールキーです。Identity に含まれると、**Voter がすべて終わったあと**の既定チェックを省略できる経路に入ります。
- **ロール→権限コード:** `LookupInterface` でアプリのロールを権限コードへ対応付けします。
- **コンテキスト依存:** リクエストやドメイン（所有権・テナント・IP など）に依存する場合は `VoterInterface` を使います。
- **判定イベント:** 許可・拒否時にイベントが発火します。

## インストール {#installation}

**要件:** PHP 8.3 以上

```bash
composer require switon/authz
```

## クイックスタート {#quick-start}

コントローラでは `#[Authorize]`、サービスでは `AuthorizationInterface` を使います。

```php
use Switon\Authorizing\Attribute\Authorize;
use Switon\Authorizing\AuthorizationInterface;
use Switon\Core\Attribute\Autowired;

#[Authorize(Authorize::AUTHENTICATED)]
class AccountController
{
    #[Authorize(Authorize::ANONYMOUS)]
    public function loginAction(): array
    {
        return ['ok' => true];
    }

    public function profileAction(): array
    {
        return ['ok' => true];
    }
}

class OrderService
{
    #[Autowired] protected AuthorizationInterface $authorization;

    public function cancel(int $orderId): void
    {
        $this->authorization->authorize('order::cancel');
    }
}
```

`can()` は `true` / `false` を返します。`authorize()` は拒否時に HTTP 例外を投げ、イベントを発火します。

## コア概念 {#core-concepts}

### 属性優先の規則 {#attribute-first-rules}

- クラスに `#[Authorize]` を付けると **既定ルール** になり、メソッドに付けると **そのメソッドだけ** 上書きします。
- メソッド側の属性がクラス既定より優先されます。
- メソッド引数のひとつが **権限参照**（`@` で始まる文字列、例: `@edit`）のとき、その参照が属性のロール項目の代わりになります。
- `assignable` は **権限スキャンのメタデータ**（カタログや RBAC UI）だけに効き、**ランタイムの認可判定には使われません**。
- `admin::create-user` のような文字列はコントローラ／サービスで使う **権限コード** です。
- 特定のコントローラメソッドを指したいときは `FQCN::method` 形式の **完全な** クラス名とメソッドを書きます。
- **空の** `#[Authorize]` でもハンドラは認可フローに残ります（ログイン済みユーザーは Lookup まで進みます）。

### Identity の状態 {#identity-states}

- 公開アクセスと認証済みアクセスを分けるために使います。
  - 公開: `Authorize::ANONYMOUS`
  - 認証済み: `Authorize::AUTHENTICATED`

### スーパーユーザーロール {#superuser-role}

- `Authorize::SUPERUSER` は特権ロールキーです。
- Identity のロールに含まれると **グローバルバイパス** 経路に入ります。

### サービス内チェック {#service-checks}

- 拒否が通常の分岐でよいときは `AuthorizationInterface::can()`。
- 拒否を `401` / `403` にしたいときは `AuthorizationInterface::authorize()`。
- ロール引数で `IdentityInterface::getRoles()` を上書きできます。
- `authorize()` は成功時に `AuthorizationGranted`、失敗時に `AuthorizationDenied` を発火します。

### ロール→権限コード対応 {#permission-lookup}

アプリケーションのロールを権限コードへ対応付けるときは `LookupInterface` を使います。

- コードは **完全一致** で扱います。
- **スペースを入れない**でください。
- Lookup の設定に `anonymous` や `authenticated` を **書かない**でください（状態マーカでありビジネスロールの対応ではありません）。

### Voter {#voters}

所有権・テナント・IP など、コンテキストが必要なときは `VoterInterface` を使います。

- `true`: 許可し、**これ以降の Voter は実行されません**
- `false`: 拒否し、**これ以降の Voter は実行されません**
- `null`: **棄権**し、既定のチェック（組み込みのロール経路など）が続きます
- **最初に** `null` 以外を返した Voter が勝ちます。
- Voter は **`Authorize::SUPERUSER` を含む組み込みロールチェックの前** に実行されるため、`false` でスーパーユーザーでも拒否できます。

```php
use ReflectionMethod;
use Switon\Authorizing\VoterInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Http\RequestInterface;

class IpAllowlistVoter implements VoterInterface
{
    #[Autowired] protected RequestInterface $request;

    public function vote(string $permission, ?ReflectionMethod $handler): ?bool
    {
        if (!str_starts_with($permission, 'admin::')) {
            return null;
        }

        if ($this->request->ip() !== '127.0.0.1') {
            return false;
        }

        return null;
    }
}
```

## 設定 {#configuration}

### ロール→権限コード {#role-mapping}

```yaml
# switon.yml
Switon\Authorizing\LookupInterface:
  permissions:
    admin: admin::create-user,admin::delete-user
    rbac-manager: rbac.role::index,rbac.role::edit
```

権限が DB・キャッシュ・外部ポリシーにある場合は、`LookupInterface` を自作してください。

### Voter {#voters-config}

```yaml
# switon.yml
Switon\Authorizing\AuthorizationInterface:
  voters:
    - App\Auth\IpAllowlistVoter
```

リスト順に評価します。`true` と `false` でチェーンは終了、`null` のときは既定チェックへ進みます。

## 注意点 {#keep-in-mind}

- ロールを省略した `#[Authorize]` は **公開を意味しません**。公開には `Authorize::ANONYMOUS` を使います。
- `Authorize::AUTHENTICATED` は **ログイン状態のマーカー**であり、ビジネスロール名ではありません。
- イベントまで含めて強制したいときは `authorize()`、分岐だけなら `can()` です。
- 既定の流れは **Voter → Identity が `SUPERUSER` か → ハンドラ属性 → Lookup** です。
- HTTP リクエストは `Switon\Http\Event\RequestAuthorizing` で自動認可されます。コントローラ外では直接 `authorize()` を呼びます。
- Lookup の値は厳密に。スペースを含めないでください。

## 次のステップ {#next}

- **ガイド（英語）:** [認可を追加する](/en/guides/add-authorization) · [認証を追加する](/en/guides/add-authentication)
- **FAQ:** [FAQ](faq.md)
- **設計:** [設計の狙い](design.md)
