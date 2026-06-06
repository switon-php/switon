# Controller {#controller}

Define HTTP endpoints with route attributes, action parameters, typed input, and controller-scoped extension points.

**Key features:**

- **Attribute routing** - `#[RequestMapping]` sets the controller prefix, and `#[GetMapping]`, `#[PostMapping]`, and similar attributes map verbs to actions
- **Action parameters** - route values, request input, and DI services can share one action signature
- **Typed input** - `#[RequestBody]`, `#[RequestQuery]`, and `#[RequestData]` bind typed input classes with validation
- **Action-level extension points** - views, authorization, and interceptors stay close to the action they affect
- **Central exception flow** - controller errors converge through the HTTP exception path instead of local `try/catch`

## Quick Start {#quick-start}

Put one controller under `app/Controller/` or `app/Areas/*/Controller/`, add `#[RequestMapping]` on the class, and add verb attributes on actions.

```php
namespace App\Controller;

use Switon\Http\Controller;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/user')]
class UserController extends Controller
{
    #[GetMapping('')]
    public function indexAction(int $page = 1): array
    {
        return ['page' => $page];
    }

    #[GetMapping('{id}')]
    public function showAction(int $id): array
    {
        return ['id' => $id];
    }

    #[PostMapping('')]
    public function createAction(string $name, string $email): array
    {
        return ['name' => $name, 'email' => $email];
    }
}
```

Route discovery runs at startup. Use `bash bin/console router:list` when a route does not appear.

## Core Concepts {#core-concepts}

### Routing {#routing}

Controller routes come from attributes on classes and actions.

- `#[RequestMapping]` sets the controller prefix
- `#[GetMapping]`, `#[PostMapping]`, `#[PutMapping]`, `#[PatchMapping]`, and `#[DeleteMapping]` map verbs
- `#[ViewGetMapping]`, `#[ViewPostMapping]`, and `#[ViewMapping]` are for view-oriented routes

### Action Parameters {#action-parameters}

Action parameters are resolved by name or by type.

- route values bind by name
- scalar action parameters read from request input
- service parameters resolve from the container

Use a small action signature first. Move request access into `Switon\Http\RequestInterface` only when the action needs headers, files, or other raw request data.

### Typed Input {#typed-input}

Use typed input classes when one action needs a stable request shape.

- `#[Switon\Http\Attribute\RequestBody]` reads parsed body input
- `#[Switon\Http\Attribute\RequestQuery]` reads query input
- `#[Switon\Http\Attribute\RequestData]` reads merged request input

Nested typed properties do not repeat the attribute. Validation is applied during binding.

### Authorization {#authorization}

Authorization attributes guard the action boundary.
Interceptors wrap only the actions they are attached to.

### View Rendering {#view-rendering}

Use the view layer when the controller action should render HTML instead of returning a plain JSON response.

- `#[ViewGetMapping]` renders HTML for normal GET requests, but still invokes the action when the client prefers JSON
- `#[ViewMapping]` always renders HTML on GET and still invokes the action for data prep

JSON-preferred requests can skip execution of the action method for `#[ViewGetMapping]`; `#[ViewMapping]` does not skip it.

### Exceptions {#exceptions}

Controller actions do not need local exception rendering.

- framework exceptions converge through the HTTP exception dispatcher
- controller code can focus on normal return values
- use custom handlers only when the default HTTP response policy is not enough

## Keep in Mind {#keep-in-mind}

- `#[GetMapping('')]` keeps only the class prefix.
- `#[GetMapping]` uses the action name for the route suffix.
- Return arrays for the common JSON path.
- Use `Switon\Http\RequestInterface` only when action parameters are not enough.

## Next {#next}

- **To read input and request metadata:** [Request](request.md#request)
- **To shape responses:** [Response](response.md#response)
- **To bind typed input in detail:** [Typed input binding](typed-input-binding.md#typed-input-binding)
- **To see common questions:** [FAQ](faq.md#http-faq)
