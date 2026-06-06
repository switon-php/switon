# Response {#response}

Shape HTTP responses through normal action returns or `Switon\Http\ResponseInterface` when you need explicit control.

**Key features:**

- **Array return path** - return arrays for the common JSON response flow
- **Explicit response control** - set status, headers, cookies, redirects, files, and body content with `ResponseInterface`
- **Renderers** - use `XmlRendererInterface`, `CsvRendererInterface`, and `AttachmentRendererInterface` for non-JSON output
- **Streaming** - write chunked output when the response is not a single buffered body
- **Central finalization** - response shaping still passes through the HTTP lifecycle

## Quick Start {#quick-start}

Return an array for the normal JSON path.

```php
namespace App\Controller;

use Switon\Http\Controller;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/users')]
class UserController extends Controller
{
    #[GetMapping('')]
    public function indexAction(): array
    {
        return ['users' => ['Alice', 'Bob']];
    }
}
```

Use `Switon\Http\ResponseInterface` when you need status codes, headers, redirects, downloads, or streaming.

## Core Concepts {#core-concepts}

### Normal Response Path {#normal-response-path}

Returning an array is the common path for JSON responses.

- the framework normalizes the return value
- the action stays focused on application data
- use this path when the response shape is ordinary JSON

### Action Return Values {#action-return-values}

| Return type | Response | Typical use |
|---|---|---|
| `array` | Normal JSON payload | Most actions |
| `null` | Empty success payload | No data to return |
| `int` | Business code payload | Simple status shorthand |
| `string` | String payload | Direct text response or other string-based contract |
| `ResponseInterface` | Already handled | Explicit response control |
| `Throwable` | Exception flow | Hand off to HTTP exception handling |

### Explicit Response Control {#explicit-response-control}

Inject `Switon\Http\ResponseInterface` when the action needs to write response metadata directly.

- `setStatus()` sets the status code
- `setHeader()` and `setCookie()` set headers and cookies
- `redirect()` prepares a redirect and stops the current flow
- `setFile()` and `write()` cover file and chunked output

### Caching {#caching}

Use response caching only when the whole response is safe to reuse for a request-shaped key.

### Renderers {#renderers}

Use renderer contracts when the output format itself is the requirement.

- `Switon\Http\Response\XmlRendererInterface`
- `Switon\Http\Response\CsvRendererInterface`
- `Switon\Http\Response\AttachmentRendererInterface`

## Keep in Mind {#keep-in-mind}

- `Switon\Http\Response::redirect()` stops the current flow intentionally.
- `Switon\Http\ResponseInterface::json()` is for explicit JSON output.
- Use renderers only when JSON is no longer the target format.

## Next {#next}

- **To bind controller actions:** [Controller](controller.md#controller)
- **To read request input:** [Request](request.md#request)
- **To choose runtime settings:** [Server](server.md#server-deployment)
