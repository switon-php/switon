# Router Package {#router-package}

`switon/router` discovers controller mappings, builds the route table, and matches requests against literal and dynamic paths. It owns path-to-handler resolution only; request binding and response shaping stay with `switon/http`.

**Key features:**

- **Attribute discovery:** `#[RequestMapping]` plus verb mappings keep route intent on the controller.
- **Predictable composition:** controller prefixes and method paths combine by stable rules.
- **Mixed matching:** literal routes use exact lookup; placeholder routes use compiled patterns.
- **Prefix handling:** `setPrefix()` accepts a fixed prefix or a conditional pattern such as `?/v\d+`.
- **Diagnostics:** `router:list`, `router:resolve`, and `router:prefix` inspect what is registered and what would match; `router:resolve` can emit JSON.

---

## Installation {#installation}

**Requirements:** PHP 8.3+, `switon/core`, `switon/event`, `psr/event-dispatcher`

```bash
composer require switon/router
```

---

## Quick Start {#quick-start}

```php
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/user')]
class UserController
{
    #[GetMapping('{id}')]
    public function showAction(): void
    {
    }
}
```

This controller registers `GET /user/{id}`.

---

## Core Concepts {#core-concepts}

### Discovery {#discovery}

- `Switon\Routing\MappingDiscoveryInterface` resolves controller mappings.
- Class-level `#[RequestMapping]` is required for discovery.
- Public methods without a mapping attribute are ignored.
- If a controller has multiple `#[RequestMapping]` attributes, discovery uses the first one.

### Mappings {#mappings}

- Class-level `#[RequestMapping]` marks a controller for discovery and sets its prefix.
- Method-level verb mappings (`#[GetMapping]`, `#[PostMapping]`, etc.) set the HTTP method.
- Method-level `#[RequestMapping]` uses `*` as the HTTP method (any verb).

### View Mappings {#view-mappings}

`ViewGetMapping`, `ViewPostMapping`, `ViewPatchMapping`, and `ViewPutMapping` follow the same path rules and add view-oriented metadata. For non-`GET` view mappings, the registrar pairs an extra `GET` route on the same path.

### Registration {#registration}

`Switon\Routing\RouteRegistrarInterface` registers discovered routes with `Switon\Routing\RouterInterface`. `register()` scans configured files; `registerClass()` adds one controller directly.

### Path Rules {#path-rules}

- `#[RequestMapping]` with no path derives a prefix from the controller name in `kebab-case`; `#[RequestMapping('')]` keeps it empty.
- A method path of `null` derives a path from the method name in `kebab-case` (with `Action` stripped); `''` keeps only the controller prefix.
- A method path starting with `/` is absolute and ignores the controller prefix.
- Array prefixes and array paths register every combination.

### Matching {#matching}

- Literal routes are exact `verb + path` matches; `*` is a verb fallback checked after the specific verb.
- Dynamic routes use placeholders such as `{id}`, `{id:int}`, `{id:[0-9]+}`. Unspecified placeholders default to one non-`/` segment.
- Built-in snippets: `{controller}`, `{action}`, `{id}`, `:uuid`, `:slug`, `:alpha`, `:alphanum`, `:any`, `:var`.
- Later dynamic registrations are checked first. Matching is case-sensitive by default.

### Prefixes {#prefixes}

`setPrefix()` strips a leading URL prefix before matching, so stored route patterns stay prefix-free.

- Fixed prefix: `setPrefix('/api/v1')` matches that exact leading path.
- Conditional prefix: `setPrefix('?/v\d+')` matches a leading token plus the pattern; for example `getPrefix('/tenant/v2/users')` returns `/tenant/v2`.
- `getPrefix($uri)` raises `NotFoundRouteException` when a URI does not satisfy a conditional prefix.

---

## Configuration {#configuration}

Start with one prefix and one route table. Use a conditional prefix only when the same app answers under a variable leading segment (tenant, version, etc.).

Default controller scan paths: `@app/Controller/*Controller.php` and `@app/Areas/*/Controller/*Controller.php`. `RouteRegistrar` can read and write a JSON cache at `@runtime/cache/routes.json` when `cache` is enabled; delete that file to refresh cached routes.

```bash
bash bin/console router:list
bash bin/console router:resolve /api/users/1 GET
bash bin/console router:prefix
```

`router:resolve` returns `matched`, `path`, `verb`, `results`, and `error` in JSON mode and tries common verbs when one is omitted.

---

## Keep in Mind {#keep-in-mind}

- Class-level `#[RequestMapping]` is required for discovery; only the first one on a class is used.
- `#[GetMapping]` on a method with no path becomes the method name in kebab-case (with `Action` stripped).
- The router only matches paths and extracts strings; type casting belongs to the request binding layer.
- `RouterCollector` exposes route data through the inspector collector named `router`; `RouteNotFound` and `RouteParamsResolved` events help with diagnostics.

---

## Next {#next}

- **Request binding or response shaping:** [HTTP Controller Guide](https://github.com/switon-php/http/blob/main/docs/controller.md#controller)
- **Common questions:** [FAQ](faq.md#router-faq)
- **Design trade-offs:** [Design Rationale](design.md#design-rationale)
