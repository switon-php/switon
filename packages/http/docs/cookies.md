# Cookies {#cookies}

Read and queue cookies through `Switon\Http\CookiesInterface`.

**Key features:**

- **Separate service** - cookie reads and queued writes stay outside `RequestInterface` and `ResponseInterface`
- **Request scoped** - cookie state stays isolated per request
- **Simple API** - `get()`, `set()`, `has()`, `delete()`, and `all()`

## Quick Start {#quick-start}

Inject `Switon\Http\CookiesInterface` when an action needs cookie reads or writes.

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

## Core Concepts {#core-concepts}

### Reading {#reading}

Use `get()`, `has()`, and `all()` for incoming cookies.

### Writing {#writing}

Use `set()` to queue a cookie on the current response.
Use `delete()` to queue an expired cookie.

### Scope {#scope}

Cookie state is request scoped, so the same API works in long-running runtimes without leaking between requests.

## Keep in Mind {#keep-in-mind}

- Cookie deletion must match the original path and domain when those were used.
- Set cookies before the response body is sent.

## Next {#next}

- **To read request input:** [Request](request.md#request)
- **To shape responses:** [Response](response.md#response)
- **To see common questions:** [FAQ](faq.md#http-faq)
