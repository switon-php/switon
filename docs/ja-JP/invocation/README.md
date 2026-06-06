---
sidebar_label: Invocation パッケージ
description: switon/invocation：Guard・Interceptor のメソッド単位フック契約。
---

# Invocation パッケージ {#invocation-package}

`switon/invocation` は **ガードとインタセプタ向けのメソッド単位フック契約** を定義します。

属性とインターフェースのみを提供し、実際の実行は **[Invoker](/en/invoker/)** が `Switon\Invoking\InvokerInterface` 経由の呼び出し時にこれらのフックを走らせます。

**主な機能:**

- **属性ベースのフック:** PHP 属性でガードとインタセプタをメソッドに付与
- **実行前チェック:** ガードハンドラは管理対象メソッド本体の前に実行
- **呼び出しの周囲ラップ:** インタセプタは管理対象呼び出しを包む（前・後・例外時）
- **共通基底:** `Switon\Invocation\Attribute\Interceptor` がよくある部分ライフサイクル向け

---

## インストール {#installation}

**要件:** PHP 8.3 以上

```bash
composer require switon/invocation
```

---

## クイックスタート {#quick-start}

ガードは現在の `ReflectionMethod` とコンストラクタで宣言した依存を受け取る。実行を止めたいときはスロー。

```php
use Attribute;
use ReflectionMethod;
use Switon\Invocation\Attribute\GuardInterface;
use Switon\Http\Exception\ForbiddenException;
use Switon\Http\RequestInterface;

#[Attribute(Attribute::TARGET_METHOD)]
final class IpGuard implements GuardInterface
{
    public function __construct(
        private array $ips,
    ) {
    }

    public function handle(RequestInterface $request, ReflectionMethod $method): void
    {
        if (!in_array($request->ip(), $this->ips, true)) {
            throw ForbiddenException::of('Access denied.');
        }
    }
}

final class AccountService
{
    #[IpGuard(['127.0.0.1'])]
    public function deleteAction(int $id): void
    {
    }
}
```

---

## 次のステップ {#next}

- **ガード付き・インタセプト付きメソッドを単一入口で実行:** [Invoker パッケージ](/en/invoker/)
- **`invoke()` 前に HTTP・CLI・ビルダーから引数を解決:** [Binding パッケージ](/ja-JP/binding/)
