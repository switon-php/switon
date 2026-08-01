# MCP Package {#mcp-package}

`switon/mcp` serves an application tool catalog over the Model Context Protocol. Choose it when those tools should be callable from MCP clients over stdio or streamable HTTP. The package standardizes the catalog, light argument binding, and the JSON-RPC envelope. Each tool's behavior, the HTTP route, and authorization stay in the app.

**Key features:**

- **Tool contract:** one class implements `schema()` and `call()`.
- **One catalog:** `Switon\Mcp\ToolsInterface` is the name map clients see.
- **Discovery:** classes under `@app/Mcp/Tool/*Tool.php` join that map unless the name is already registered.
- **Light binding:** defaults, required fields, and string/integer coercion. Extra fields stay. Booleans are rejected for those two types.
- **Two transports:** stdio for desktop clients, streamable HTTP when the app owns the route.

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/mcp
```

Streamable HTTP also needs `switon/http`. Stdio does not.

## Quick Start {#quick-start}

Put one tool under `app/Mcp/Tool/`. The short class name without a trailing `Tool`, in snake_case, is the published name (`NowTool` becomes `now`).

```php
namespace App\Mcp\Tool;

use App\Service\OrderService;
use Switon\Core\Attribute\Autowired;
use Switon\Mcp\AbstractTool;
use Switon\Mcp\ToolSchema;

class OrderStatusTool extends AbstractTool
{
    #[Autowired] protected OrderService $orders;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            description: 'Return order status by order ID',
            properties: [
                'orderId' => ['type' => 'integer'],
            ],
            required: ['orderId'],
        );
    }

    public function call(string $name, array $arguments): mixed
    {
        return $this->orders->statusOf($arguments['orderId']);
    }
}
```

Serve it over stdio:

```bash
bash bin/mcp-serve
```

That entry runs `mcp:serve` until stdin closes. An explicit name in config uses the key you write, and it wins over discovery:

```yaml
# switon.yml
Switon\Mcp\ToolsInterface:
  tools:
    order_get_status: App\Mcp\Tool\OrderStatusTool
```

## Core Concepts {#core-concepts}

Order follows the request: **catalog**, then **arguments**, then **result**, then **transport**.

### Catalog {#catalog}

A tool is a `Switon\Mcp\ToolInterface`: `schema()` describes arguments, `call()` runs them. `tools/list` and `tools/call` only carry the tool name and an arguments object.

`schema()` returning `null` hides that name from `all()` and `get()`. `has()` still reports the raw map entry, so discovery will not replace it.

Cache the schema on the tool (`$this->schema ??= ...`). Listing and binding may call `schema()` more than once.

### Arguments {#arguments}

`Switon\Mcp\ArgumentBinderInterface` prepares the arguments object before `call()`.

- A property `default` fills a missing key.
- `required` uses `isset`, so an explicit `null` still fails.
- `string` accepts a string, or an int/float rendered with `(string)`.
- `integer` accepts an int, or a string of digits with an optional sign.
- Booleans fail both of those types. `"1"` and `1` still coerce into each other.
- Other schema types are left unchanged.
- Extra keys are kept. `additionalProperties: false` is written into the published schema only when you pass that flag. The binder does not reject extra keys.

This is not a full JSON Schema engine.

### Call result {#call-result}

`call()` may return a plain value or a `Switon\Mcp\ToolResult`. A plain value becomes a successful result. Throw, or return `ToolResult` with a throwable, for a tool failure.

Tool text uses JSON literals. `true`, `false`, and `null` stay `true`, `false`, and `null` in the text block.

A missing, empty, or non-string tool name, or a non-object `arguments`, is JSON-RPC `-32602`. An unknown tool, a binder failure, and a throw from `call()` stay inside the result with `isError: true` and text `{class, content}`. Override `Switon\Mcp\ProtocolHandler::call()` and rethrow `Switon\Mcp\Exception\InvalidParamsException` when that failure should be `-32602` instead.

`Switon\Mcp\Event\ToolCalling` runs after the tool is found and before binding. `Switon\Mcp\Event\ToolCalled` runs after a resolved tool returns, and its `result` is what the client receives. An unknown tool skips both.

### Transport {#transport}

Stdio and streamable HTTP share `Switon\Mcp\ProtocolHandlerInterface` and the same catalog.

Stdio is `bash bin/mcp-serve`. The process speaks JSON-RPC on stdin/stdout until stdin closes.

Streamable HTTP is an app controller. Inject `Switon\Mcp\HttpServerInterface`, return `handle()` from `POST`, and return `terminate()` from `DELETE` (HTTP 204). `handle()` checks `Origin` and `Accept`, then writes the JSON-RPC body. A successful `initialize` sets response header `Mcp-Session-Id` (20 characters). The client sends that header on later requests. Tools read it, plus `Authorization: Bearer` or query `access_key`, from `Switon\Mcp\SessionInterface`.

`resources/list` and `prompts/list` answer with empty lists. This package does not implement resources or prompts.

## Configuration {#configuration}

Register an explicit tool when the published name should not follow the class name. Leave discovery on for `app/Mcp/Tool/*Tool.php`.

```yaml
# switon.yml
Switon\Mcp\ToolsInterface:
  tools:
    order_get_status: App\Mcp\Tool\OrderStatusTool

Switon\Mcp\ServerInfoInterface:
  name: my-app
  version: '1.0.0'
```

`ServerInfoInterface` supplies `initialize`: `name`, `version`, optional `description` (sent as `instructions`), and the protocol version used when the client does not request `2025-03-26` or `2024-11-05`.

When `Origin` is present, an empty `allowedOrigins` allows only the request's own scheme and host. Set the list to allow other origins:

```yaml
Switon\Mcp\Transport\HttpTransportInterface:
  allowedOrigins:
    - https://app.example.com
```

### Option Discovery {#option-discovery}

Run:

```bash
bash bin/console config:options 'Switon\Mcp\ToolsInterface'
bash bin/console config:options 'Switon\Mcp\ToolDiscoveryInterface'
bash bin/console config:options 'Switon\Mcp\ServerInfoInterface'
bash bin/console config:options 'Switon\Mcp\Transport\HttpTransportInterface'
```

## Keep in Mind {#keep-in-mind}

- A tool missing from `tools/list` usually has `schema()` returning `null`, or discovery skipped the name because it was already registered.
- Binder failures are tool results unless a custom `ProtocolHandler::call()` lets `InvalidParamsException` escape.
- HTTP `Accept` must include both `application/json` and `text/event-stream`.

## Next {#next}

- **To debug behavior and defaults:** [FAQ](faq.md#mcp-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
