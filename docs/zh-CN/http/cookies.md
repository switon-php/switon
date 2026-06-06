---
sidebar_label: Cookie
description: 经 CookiesInterface 读取与排队写入 Cookie。
---

# Cookie {#cookies}

经 `Switon\Http\CookiesInterface` 读取并排队写入 Cookie。

**关键特性：**

- **独立服务** — Cookie 的读取与排队写入与 `RequestInterface`、`ResponseInterface` 分离
- **请求作用域** — Cookie 状态按请求隔离
- **简洁 API** — `get()`、`set()`、`has()`、`delete()`、`all()`

## 快速开始 {#quick-start}

动作需要读或写 Cookie 时，注入 `Switon\Http\CookiesInterface`。

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

## 核心概念 {#core-concepts}

### 读取 {#reading}

入站 Cookie 使用 `get()`、`has()`、`all()`。

### 写入 {#writing}

`set()` 在当前响应上排队 Cookie；`delete()` 排队过期 Cookie。

### 作用域 {#scope}

Cookie 状态按请求隔离，同一 API 在长驻运行时也不会在请求间泄漏。

## 注意 {#keep-in-mind}

- 删除 Cookie 时，若当初设置了 path 与 domain，须与之一致。
- 在响应正文发出前设置 Cookie。

## 下一步 {#next}

- **读取请求输入：** [请求](request.md)
- **构造响应：** [响应](response.md)
- **常见问题：** [FAQ](faq.md)
