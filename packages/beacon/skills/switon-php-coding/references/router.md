# Router (define routes / find handler)

Define or change routes (attributes + config/router.php), or resolve path → handler. Use CLI to list/resolve; no manual
route list.

## 1. Core API

- **Interface:** `Switon\Routing\RouterInterface` — `match($uri, $verb)` (throws when no route), `getAllRoutes()`,
  `getPrefix($uri?)`, `setPrefix()`, `add` / `addGet` / `addPost` …
- **Implementation:** `Switon\Routing\Router` (switon/router). Resource routes: use attributes or multiple `add*` calls
  per path/verb.

## 2. Prefix (config/router.php)

- **Static:** e.g. `'/api'`; empty string = no prefix. `getPrefix()` returns as-is.
- **Dynamic:** `?/path` — first segment any `a-zA-Z0-9_-`. No path arg → returns config; path mismatch → throws. With
  URI arg and match, returns resolved prefix (e.g. `getPrefix("/en/time/current")` with `?/time` → `"/en/time"`).
- `match($uri)` receives the full request path (including prefix). Strip query string before calling `match()` (e.g. use
  path only from URL).

## 3. Defining routes

- **Attributes:** `#[RequestMapping]` on class; `#[GetMapping]` etc. on actions.
  See [controller.md](references/controller.md)
- **Registrar:** `Switon\Routing\RouteRegistrar`; manual `add*` usually unnecessary (use when adding routes in code
  without attributes, e.g. closures or non-controller handlers).

## 4. Handler ID

Handler ID is
`ClassName::dotId($controllerFqcn, compact: true) . '::' . Naming::kebab(basename($actionMethod, 'Action'))`. CLI
`router:list` / `router:resolve` split the route handler string on `::` once, then apply that. In HTTP-facing code (
authz, controllers), use `Switon\Http\HandlerIdInterface::getId($controller, $action)` (default
`Switon\Http\HandlerId`). Format: kebab segments, both sides required. Examples: `App\Controller\UserController` +
`indexAction` → `user::index`; `App\Areas\Admin\Controller\AccountController` + `captchaAction` →
`admin.account::captcha`.

## 5. Tools

- **router:resolve**, **router:list**, **router:prefix**

## 6. When to use

- Add/change endpoint → attributes + config; verify with list/resolve
- User gives path → router:resolve, use file + lineStart–lineEnd to open handler
