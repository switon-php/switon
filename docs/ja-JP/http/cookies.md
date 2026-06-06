---
sidebar_label: Cookies
description: CookiesInterface で Cookie の読み取りとキュー書き込み。
---

# Cookies {#cookies}

`Switon\Http\CookiesInterface` で Cookie を読み、レスポンス用にキューする。

**主な機能:**

- **独立サービス** — 読み書きを `RequestInterface`・`ResponseInterface` から分離
- **リクエストスコープ** — Cookie 状態はリクエストごとに分離
- **単純 API** — `get()`・`set()`・`has()`・`delete()`・`all()`

## クイックスタート {#quick-start}

Cookie の読み書きが要るときは `CookiesInterface` を注入。

```php
namespace App\Controller;

use Switon\Http\CookiesInterface;
use Switon\Http\Controller;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/dashboard')]
class DashboardController extends Controller
{
    #[GetMapping('')]
    public function indexAction(CookiesInterface $cookies): array
    {
        $token = $cookies->get('token');
        $cookies->set('last_seen', 'dashboard', 3600);

        return ['token' => $token];
    }
}
```

## コア概念 {#core-concepts}

### 読み取り {#reading}

受信 Cookie は `get()`・`has()`・`all()`。

### 書き込み {#writing}

`set()` で現在レスポンスに Cookie をキュー。
`delete()` で期限切れ Cookie をキュー。

### スコープ {#scope}

Cookie 状態はリクエストスコープのため、長寿命ランタイムでもリクエスト間で漏れない。

## 注意点 {#keep-in-mind}

- 削除時は元の path・domain を合わせる（設定していた場合）。
- レスポンスボディ送信前に Cookie を設定する。

## 次のステップ {#next}

- **リクエスト入力:** [Request](request.md)
- **レスポンス:** [Response](response.md)
- **FAQ:** [FAQ](faq.md)
