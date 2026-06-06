# Call an upstream API {#call-an-upstream-api}

Use this guide when one working business path should now call a third-party or internal upstream API without turning controllers, retries, or transport details into the center of the application too early.

## Choose the first outbound-HTTP boundary {#http-boundary}

Start with the boundary that matches the real integration problem.

| Boundary | Best when | Keep in mind |
|---|---|---|
| [HTTP Client](../http-client/README.md) | one application service can call one upstream directly and the caller can wait for the result | strongest default for the first JSON API integration; typed exceptions already model status and transport failures |
| [Factory](../di/factory.md#factory) | different upstreams need different default timeout, pool, proxy, or TLS settings | keep the application service boundary the same; only the client selection changes |
| [Queue](../queue/README.md) | user-facing latency, retry needs, or process separation matter more than immediate completion | keep the same application gateway and move only the producer-to-worker handoff |
| [Log](../log/README.md) | the call already works and the next problem is visibility into timeout, upstream status, or failure trends | logging and metrics should observe the seam, not replace its failure policy |

One common application shape is:

- **Controller / CLI / task** to decide that the business path needs upstream data
- **Application gateway service** to own request building, failure mapping, and response parsing
- **`Switon\HttpClient\HttpClientInterface`** to execute the outbound call
- **`Switon\Di\Factory`** only when named upstream clients need different defaults
- **`Queue`** only when the caller should not wait for the upstream round trip

## Recommended build order {#build-order}

### 1. Start after one business action is already clear {#build-business-action}

Do not let the first upstream integration define your controller or job shape.
First know which business action needs outside data or remote side effects, then wrap that need in one application-owned gateway or service.

### 2. Keep one application gateway between business logic and transport {#build-gateway}

Do not call `Switon\HttpClient\HttpClientInterface` directly from many controllers, commands, or workers.
Keep one boundary such as `CatalogGatewayInterface`, `BillingGatewayInterface`, or `AddressLookupServiceInterface` so request building, headers, and failure mapping stay in one place.

### 3. Start with JSON shortcuts for normal APIs {#build-json}

For the common path, use `get/post/put/patch/delete` and read the payload with `$response->json()`.
Use `head()` only for header or existence checks.
Use `request()` only when the upstream expects form data, plain text, webhook-style payloads, or another explicit content contract.

### 4. Treat failures as part of the contract {#build-failure-policy}

The first integration should name at least one HTTP-status failure and one transport failure.
Typical first cases are:

- `Switon\HttpClient\Exception\NotFoundException` when the upstream record does not exist
- `Switon\HttpClient\Exception\TooManyRequestsException` when caller rate or provider quota should surface clearly
- `Switon\HttpClient\Exception\TimeoutException` or `ConnectTimeoutException` when latency or reachability should be logged and retried later
- `Switon\HttpClient\Exception\BadResponseException` when the normal JSON path receives the wrong response shape

### 5. Set small defaults globally, then override exceptional requests locally {#build-timeouts}

Start with one global `timeout`, `connect_timeout`, `verify_peer`, and `pool_size` in `switon.yml`.
Then use `RequestOptions::of(...)` for the small number of slow, proxied, or unusual requests.
That keeps the default path readable while still making exceptional latency policy visible.

### 6. Split named clients only when different upstreams really need different defaults {#build-named-clients}

If every upstream shares one small timeout and network policy, keep one client first.
Add `Switon\Di\Factory` only when one upstream needs a slower timeout, different proxy, or different pool sizing than the others.

### 7. Move the same gateway behind durable work only when waiting becomes the problem {#build-queue}

If the caller should finish without waiting on the upstream round trip, keep the same application gateway and change only the handoff.
That is the point to use [Add durable background work](add-durable-background-work.md#add-durable-background-work), not a reason to move request-building logic into queue jobs.

### 8. Stop before retry platforms or provider architecture {#build-stop}

The first outbound-HTTP win does **not** require a generic SDK layer, multi-provider fallback, or broad retry orchestration.
Make one upstream path trustworthy before you widen the system.

## Minimal wiring {#minimal-wiring}

Use short code only to show the seam between business logic, application-owned gateway code, and the outbound HTTP transport.

Application-level names like `InventoryGatewayInterface`, `InventoryGateway`, and `CheckoutService` below are illustrative. The stable part is the Switon `HttpClientInterface` boundary, the `RequestOptions` override path, and the optional `Factory` split for named clients.

### Keep one application gateway for the upstream contract {#wiring-gateway}

Define one app-owned boundary first:

```php
namespace App\Gateway;

interface InventoryGatewayInterface
{
    public function reserve(string $sku, int $quantity): array;
}
```

Implement it with `Switon\HttpClient\HttpClientInterface` and explicit failure handling:

```php
namespace App\Gateway;

use Psr\Log\LoggerInterface;
use Switon\Core\Attribute\Autowired;
use Switon\HttpClient\Exception\BadResponseException;
use Switon\HttpClient\Exception\TimeoutException;
use Switon\HttpClient\Exception\TooManyRequestsException;
use Switon\HttpClient\HttpClientInterface;
use Switon\HttpClient\RequestOptions;

final class InventoryGateway implements InventoryGatewayInterface
{
    #[Autowired] protected HttpClientInterface $httpClient;
    #[Autowired] protected LoggerInterface $logger;

    public function reserve(string $sku, int $quantity): array
    {
        try {
            $response = $this->httpClient->post(
                'https://inventory.example.com/api/reservations',
                ['sku' => $sku, 'quantity' => $quantity],
                options: RequestOptions::of([
                    RequestOptions::TIMEOUT => 5,
                    RequestOptions::CONNECT_TIMEOUT => 2,
                ])
            );

            return $response->json();
        } catch (TooManyRequestsException $e) {
            $this->logger->warning('Inventory upstream throttled reservation', [
                'sku' => $sku,
                'quantity' => $quantity,
                'exception' => $e,
            ]);
            throw $e;
        } catch (TimeoutException|BadResponseException $e) {
            $this->logger->error('Inventory upstream failed reservation', [
                'sku' => $sku,
                'quantity' => $quantity,
                'exception' => $e,
            ]);
            throw $e;
        }
    }
}
```

This keeps responsibilities clear:

- the gateway decides **how the upstream contract is called**
- `HttpClientInterface` decides **how the request is executed**
- the logger decides **how success and failure become visible**

### Keep business services above the gateway boundary {#wiring-service}

Call the gateway from application business logic instead of from controllers directly:

```php
namespace App\Service;

use App\Gateway\InventoryGatewayInterface;
use Switon\Core\Attribute\Autowired;

final class CheckoutService
{
    #[Autowired] protected InventoryGatewayInterface $inventory;

    public function reserveItem(string $sku, int $quantity): array
    {
        return $this->inventory->reserve($sku, $quantity);
    }
}
```

That keeps HTTP details reusable from HTTP, CLI, scheduled tasks, or queue consumers.

### Keep default client config small and explicit {#wiring-config}

Start with one shared default in `switon.yml`:

```yaml
# switon.yml
Switon\HttpClient\HttpClientInterface:
  timeout: 3
  connect_timeout: 1
  verify_peer: true
  pool_size: 4
```

Add `proxy` or `cafile` only when the network boundary actually needs them.
For the full option surface, use [HTTP Client configuration discovery](../http-client/README.md#option-discovery).

### Use `request()` only when the upstream contract is not the normal JSON path {#wiring-raw-request}

For form-encoded token endpoints or similar contracts, keep the lower-level call explicit:

```php
use Switon\HttpClient\HttpClientInterface;

$response = $this->httpClient->request(
    'POST',
    'https://identity.example.com/oauth/token',
    ['grant_type' => 'client_credentials'],
    [HttpClientInterface::HEADER_CONTENT_TYPE => HttpClientInterface::CONTENT_TYPE_FORM]
);

$token = $response->text();
```

That is the right seam when payload and response handling should not use the JSON shortcut path.

### Split named clients only after the defaults diverge {#wiring-factory}

When two upstreams need different defaults, use `Switon\Di\Factory` instead of hard-coding transport policy in services:

```yaml
# switon.yml
Switon\HttpClient\HttpClientInterface:
  class: Switon\Di\Factory
  default:
    timeout: 3
    connect_timeout: 1
    verify_peer: true
    pool_size: 4
  billing:
    timeout: 10
    connect_timeout: 2
    verify_peer: true
    pool_size: 8
```

```php
use Switon\Core\Attribute\Autowired;
use Switon\HttpClient\HttpClientInterface;

final class BillingGateway
{
    #[Autowired] protected HttpClientInterface $billing;
}
```

That keeps the slot name in code and the transport choice in config. With `Factory`, the autowired property name `$billing` selects the `billing` client entry.

## What to avoid first {#pitfalls}

Keep the first outbound-HTTP path intentionally narrow.

Avoid these too early:

- **Calling upstream APIs directly from controllers, commands, or queue jobs** — keep one application gateway so headers, timeouts, and catches stay centralized
- **Starting with `request()` for normal JSON APIs** — the shortcut methods are the intended first path
- **Treating non-2xx responses as normal return values** — `404`, `429`, and other non-2xx responses already surface as typed exceptions
- **Leaving one short default timeout on known slow endpoints** — use `RequestOptions::of(...)` for exceptional requests instead of silently hoping the default fits all cases
- **Splitting every upstream into named clients before defaults actually differ** — start with one client and widen only when config policy diverges
- **Assuming pooling helps across constantly changing hosts** — reuse is host-based, not one global transport bucket
- **Designing retries, circuit breakers, or multi-provider failover before one call is observable** — first prove one success path and one visible failure path

A beginner should feel this after finishing the first upstream-integration path:

> I know where outbound HTTP belongs, which request mode fits the contract, and how failures should surface in application code.

## Go deeper {#go-deeper}

- **To go deeper on request modes, typed exceptions, uploads, and pooling:** [HTTP Client](../http-client/README.md)
- **To split multiple upstreams by config instead of hard-coded transport policy:** [Factory](../di/factory.md#factory) · [DI](../di/README.md)
- **To move slow or retry-sensitive upstream work out of the request path:** [Add durable background work](add-durable-background-work.md#add-durable-background-work) · [Queue](../queue/README.md)
- **To add one small, trustworthy test slice around the gateway boundary:** [Add testing to a working app](add-testing-to-a-working-app.md#add-testing-to-a-working-app)
- **To observe timeout, status, and transport failures more clearly:** [Log](../log/README.md)
- **To build the inbound request path that will call this gateway:** [Build an API](build-an-api.md#build-an-api) · [HTTP](../http/README.md)
- **To align your own public API contract after upstream-backed routes stabilize:** [Add API docs](add-api-docs.md#add-api-docs) · [OpenAPI](../openapi/README.md)
