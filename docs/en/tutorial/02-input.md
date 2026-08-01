---
sidebar_label: Typed Input
description: Turn request data into typed input objects and validate it before business logic runs.
---

# 02 — Clean input {#02-input}

## Goal {#goal}

Replace raw request reads with typed input objects and validation.

**Primary packages:** [Typed input binding](../../packages/http/docs/typed-input-binding.md#typed-input-binding) · [Validation](../../packages/validation/docs/README.md)

## Code {#shape}

`CreateTaskRequest` and `TaskFilterQuery` are illustrative names.
Add the request classes under `app/Http/Request/`, then replace `app/Controller/TaskController.php` with the controller below.

```php
namespace App\Http\Request;

use Switon\Http\Attribute\RequestBody;
use Switon\Http\Attribute\RequestQuery;
use Switon\Validating\Attribute\Length;

#[RequestBody]
class CreateTaskRequest
{
    #[Length(1, 120)]
    public string $title;

    public ?string $note = null;
}

#[RequestQuery]
class TaskFilterQuery
{
    public ?bool $completed = null;
}
```

```php
namespace App\Controller;

use App\Http\Request\CreateTaskRequest;
use App\Http\Request\TaskFilterQuery;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Http\Controller;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[Authorize(Authorize::ANONYMOUS)]
#[RequestMapping('/tasks')]
class TaskController extends Controller
{
    #[GetMapping('')]
    public function listAction(TaskFilterQuery $query): array
    {
        return ['filter' => ['completed' => $query->completed], 'tasks' => []];
    }

    #[PostMapping('')]
    public function createAction(CreateTaskRequest $request): array
    {
        return [
            'task' => [
                'id' => 2,
                'title' => $request->title,
                'note' => $request->note,
                'completed' => false,
            ],
        ];
    }
}
```

Important rule: with HTTP typed binding, a non-nullable public property without a default value is treated as required.

## Steps {#steps}

1. Replace manual `$request->post()` reads with one `#[RequestBody]` object.
2. Add `#[RequestQuery]` only for read-side filters such as `completed`.
3. Keep validation attributes on input properties, not in the controller.

Beginner default:

- `#[RequestBody]` for body data
- `#[RequestQuery]` for query parameters
- `#[RequestData]` only when you really want merged route, POST, and query data

Use `#[RequestData]` only when one object must intentionally merge route, body, and query values.

## Verify {#verify}

Use the same `/tasks` routes from chapter `01`.
The controller should now receive typed objects instead of reading raw request data directly.

Try one valid request and one invalid request on `http://127.0.0.1:9501/api/tasks`:

```bash
curl -X POST http://127.0.0.1:9501/api/tasks \
  -H 'Content-Type: application/json' \
  -d '{"title":"Write docs","note":"Keep it short"}'

curl -i -X POST http://127.0.0.1:9501/api/tasks \
  -H 'Content-Type: application/json' \
  -d '{"title":""}'
```

The valid request should reach the controller with typed input.
The invalid request should return an HTTP error response instead of the normal `task` payload.
That failure should happen before business logic runs.

## Next {#next}

When typed input feels trustworthy, [03 — Service boundary](03-service.md#03-service) moves the business rule out of the controller.
