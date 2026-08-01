---
sidebar_label: HTTP Basics
description: Build the first Switon HTTP endpoints with routes, controllers, and JSON responses.
---

# 01 — HTTP endpoints {#01-http}

## Goal {#goal}

Build one minimal HTTP path with routes, a controller, and JSON responses.

**Primary packages:** [HTTP](https://github.com/switon-php/http/blob/main/docs/README.md#quick-start) · [Router](https://github.com/switon-php/router/blob/main/docs/README.md#quick-start)

## Code {#shape}

Put the controller under `app/Controller/TaskController.php`.
Default controller scan paths are `@app/Controller/*Controller.php` and `@app/Areas/*/Controller/*Controller.php`.
`TaskController` is an illustrative name. Route attributes and `Controller` behavior are the stable parts.
`Authorize::ANONYMOUS` keeps this path public. Use it when the endpoint should stay reachable before login exists.

```php
namespace App\Controller;

use Switon\Authorizing\Attribute\Authorize;
use Switon\Http\Controller;
use Switon\Http\RequestInterface;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[Authorize(Authorize::ANONYMOUS)]
#[RequestMapping('/tasks')]
class TaskController extends Controller
{
    #[GetMapping('')]
    public function listAction(): array
    {
        return [
            'tasks' => [
                ['id' => 1, 'title' => 'Read the tutorial', 'completed' => false],
            ],
        ];
    }

    #[PostMapping('')]
    public function createAction(RequestInterface $request): array
    {
        return [
            'task' => [
                'id' => 2,
                'title' => (string) $request->post('title', ''),
                'completed' => false,
            ],
        ];
    }
}
```

## Steps {#steps}

1. Start with `#[RequestMapping('/tasks')]` and one `#[GetMapping('')]` action.
2. Add `#[PostMapping('')]` and read one field from `RequestInterface`.
3. Return arrays from both actions so the route already produces JSON-shaped responses.

## Verify {#verify}

If your app is not running yet, start it first:

```bash
SERVER_TYPE=php php public/index.php
```

Check route discovery before debugging anything deeper:

```bash
bash bin/console router:list
bash bin/console router:prefix
```

- `router:list` shows what was registered
- `router:prefix` shows whether the app prepends a global public prefix such as `/api`

If the route is missing, check the controller discovery path and confirm the class has a class-level `#[RequestMapping]`.

You can also hit the route directly on `http://127.0.0.1:9501`. In the proof app and the published skeleton, that usually means `/api/tasks`:

```bash
curl http://127.0.0.1:9501/api/tasks
curl -X POST http://127.0.0.1:9501/api/tasks \
  -H 'Content-Type: application/json' \
  -d '{"title":"Read the tutorial"}'
```

## Next {#next}

When this path works, open [02 — Clean input](02-input.md#02-input).
