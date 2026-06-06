# Request {#request}

Read route values, query, body, headers, files, and attributes through one request boundary.

**Key features:**

- **Merged input** - `get()`, `has()`, `all()`, `only()`, and `except()` work on one merged request map
- **Source-specific reads** - `route()`, `query()`, and `post()` stay available when the source matters
- **File access** - `files()`, `file()`, and `hasFile()` cover uploaded files
- **Request metadata** - `header()`, `headers()`, `server()`, `ip()`, `url()`, and `path()` expose HTTP context
- **Typed input handoff** - `#[RequestBody]`, `#[RequestQuery]`, and `#[RequestData]` turn request data into typed input objects

## Quick Start {#quick-start}

Use `Switon\Http\RequestInterface` when an action needs raw request data or request metadata.

```php
namespace App\Controller;

use Switon\Http\Controller;
use Switon\Http\RequestInterface;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/search')]
class SearchController extends Controller
{
    #[GetMapping('')]
    public function searchAction(RequestInterface $request): array
    {
        return [
            'keyword' => $request->get('keyword'),
            'page' => $request->query('page', 1),
            'ip' => $request->ip(),
        ];
    }
}
```

Use source-specific reads when the source matters. Move to typed input classes when parsing or validation starts to spread.

## Core Concepts {#core-concepts}

### Merged Input {#merged-input}

`Switon\Http\RequestInterface::all()` merges route values, parsed POST/body data, and query data. Route values win on name collisions.

`Switon\Http\RequestInterface::get()` and `has()` read that same merged map.

### Source-Specific Reads {#source-specific-reads}

Use these methods when you need one source only:

- `route()` for route values
- `query()` for query string values
- `post()` for parsed body values

### Files And Metadata {#files-and-metadata}

`Switon\Http\RequestInterface` also exposes uploaded files and request metadata.

- `files()` and `file()` read uploads
- `header()` and `headers()` read headers
- `server()` reads server variables
- `ip()`, `url()`, `path()`, `verb()`, and `isAjax()` expose common request facts

### Typed Input Hand-off {#typed-hand-off}

When one action needs a stable input shape, bind a typed input class instead of reading raw request data in the action method.

- `#[RequestBody]` reads parsed body input
- `#[RequestQuery]` reads query input
- `#[RequestData]` reads merged request input

## Keep in Mind {#keep-in-mind}

- `Switon\Http\RequestInterface::get()` treats `null` like missing input.
- `Switon\Http\RequestInterface::all()` is the merged store after routing.
- JSON request bodies are parsed into POST data before the merged input is read.

## Next {#next}

- **To bind routes and controller actions:** [Controller](controller.md#controller)
- **To shape responses:** [Response](response.md#response)
- **To bind typed input in detail:** [Typed input binding](typed-input-binding.md#typed-input-binding)
