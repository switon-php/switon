# Controller (create / update / optimize / verify)

Use when the user asks to create, update, optimize, verify, or modify a controller in the app.

## Where and base class

- **Location:** (peer options)
    - **Root:** `app/Controller/`.
    - **Areas:** `app/Areas/{AreaName}/Controller/` (e.g. `app/Areas/Admin/Controller/`).
    - One class per file; filename = class name (e.g. `UserController.php`).
- **Base class:** Extend `App\Controller\Controller`. If it does not exist, create `app/Controller/Controller.php`:
    - Namespace `App\Controller`, extend `Switon\Http\Controller`.
    - Add shared `#[Autowired]` deps (e.g. `Switon\Http\RequestInterface`, `Switon\Http\ResponseInterface`,
      `Switon\Principal\IdentityInterface`) as needed.
- **Namespace:** `App\Controller` (root); `App\Areas\{AreaName}\Controller` (Area, e.g. `App\Areas\Admin\Controller`).
- **When to use an Area:**
    - Distinct module (own URL prefix, often own views; e.g. admin, RBAC, menu) → use an Area.
    - App already has Areas → put new module in new or existing Area.
    - App has no Areas (e.g. skeleton): **suggest** Areas when any of:
        - Many entities, many features, or separate fronts (e.g. API vs admin).
        - **Heuristic:** Table names use `_` and **3+ tables share a common prefix** (e.g. `user_profile`,
          `user_settings`, `user_log`), and there is **more than one such prefix** in the app (e.g. `user_*`,
          `order_*`) → consider one Area/module per prefix.
- **Area layout:**
    - Area-specific: `App\Areas\{Area}\Entity`, `App\Areas\{Area}\Repository`.
    - Shared: `App\Entity`, `App\Repository`.
    - Views: `app/Areas/{Area}/View/`.

## Class requirements

- **Name:** Class name = **singular** entity name + `Controller` (e.g. `UserController`, not `UsersController`).
- **Class-level attribute:** `#[Switon\Routing\Attribute\RequestMapping('/path')]` required (route prefix).
    - Path = controller name in **kebab-case**, singular (e.g. `UserController` → `/user`; `OrderItemController` →
      `/order-item`).
    - Area controllers: path often includes area (e.g. `/admin/account`, `/rbac/role`).
    - Prefixes like `/api` typically in router config, not per controller.
- **Deliverable (create/update):** One file per controller; class name = singular + `Controller`; `#[RequestMapping]`
  plus one routing attribute per public action; return shape conforms to
  `Switon\Http\Transformer\NormalizeActionReturnTransformer`.

## Actions (methods)

- **Routing:**
    - Required: `#[Switon\Routing\Attribute\GetMapping(...)]`, `#[PostMapping(...)]`, `#[PutMapping(...)]`,
      `#[DeleteMapping(...)]`, etc. (same namespace for other mapping attributes).
    - **Prefer relative path** (to class `RequestMapping` + router prefix); e.g. `#[GetMapping('{id}')]` under
      `#[RequestMapping('/user')]` → GET `/api/user/{id}` (if prefix `/api`).
    - Path = **string or array**; array = multiple full paths for one action (e.g.
      `#[GetMapping(['/logout', '/admin/session/logout'])]`).
- **Naming:** Use verb + `Action` suffix (e.g. `indexAction`, `createAction`, `showAction`, `updateAction`,
  `deleteAction`).
- **Dependencies:**
    - **Shared across actions** (e.g. a repository): `protected` property with `#[Autowired]` (attribute and property on
      the same line).
    - **Per-action or HTTP** (request, response, identity): inject as **method parameters** (recommended); or use
      properties if the app base Controller already injects them.
- **Request parameters (method injection):**
    - Invoker resolves route params and body into action parameters. Type-hint params (e.g. `int $id`, DTO with
      `#[RequestBody]`). **Default values** supported (e.g. `string $keyword = ''`, `int $page = 1`).
    - Unified input: `RequestInterface::get()` (route + POST + query).
- **List/search filters:** `$request->filters(['field1', 'field2'])` → clean array (empty/null stripped; operators e.g.
  `'name*='`). Use with `Query::where()`. See `RequestInterface::filters()`.
- **Action return (response):** `Switon\Http\Transformer\NormalizeActionReturnTransformer` turns the return value into
  JSON:
    - `null` → `{code: 0, msg: ''}`
    - `array` → `{code: 0, msg: '', data: array}` (usual success)
    - `string` → `{code: -1, msg: string}` (error message)
    - `int` → `{code: int, msg: ''}`
    - `ResponseInterface` → no change (content already set)
    - `Throwable` → rethrown
    - `void` → same as `null` (PHP returns null)
    - **object (e.g. entity, Paginator):** → `{code: 0, msg: '', data: value}`.
        - If object implements **`JsonSerializable`**, it is serialized via `jsonSerialize()` when encoding.
        - Use for entities, DTOs, paginated results (`Paginator`, `Page`) so client gets the right shape.
    - other → `{code: 0, msg: '', data: value}`
    - See `Switon\Http\Transformer\NormalizeActionReturnTransformer`.
- **Controller constants:** Often a **code smell**—prefer config or the component that uses them. Use in controller only
  for that controller's URL/action names.

## Optional

- **HTML views:**
    - GET: `#[ViewGetMapping]` or `#[ViewMapping]`. POST: `#[ViewPostMapping]`. Explicit path e.g.
      `#[ViewMapping('/login')]`.
    - Typically one controller → one view file (e.g. `UserController` → `app/View/User.sword`). Areas:
      `app/Areas/{Area}/View/`.
    - See `Switon\Http\Controller` PHPDoc.
- **Authorization:**
    - `#[Authorize(Authorize::AUTHENTICATED)]` (or `Authorize::SUPERUSER`, `Authorize::ANONYMOUS`, custom role), or
      `#[Authorize]` with no args.
    - Class-level → all actions; method-level overrides class-level. See `Switon\Authorizing\Attribute\Authorize`.
- **Interceptors:**
    - Method-level attributes (e.g. `#[LogInterceptor]`); define in app (e.g. `App\Interceptors\*`).
    - Use for logging, validation, or similar before/after behavior.
- **Request context to persist:**
    - Use a **component or service** that implements `Switon\Core\ContextAware` (state in context, not in
      properties).
    - See `standards/CODE_STANDARDS.md` and `standards/COMPONENT_STANDARDS.md`.

## Controller optimization

Same rules as create/update; do not break them.

- **Git:** Uncommitted changes → remind user (commit or stash first).
- **Large changes** (extract to service, move many lines): check **last commit that touched the file** (e.g.
  `git log -1 --format=%cd -- <file>`):
    - **Within 3 days:** can apply or suggest refactors without mandatory confirmation.
    - **Older than 7 days:** get **explicit user approval** before applying.
    - **Older than 1 month:** **do not change** unless user **explicitly asked** to optimize that code.
    - **If you skip or defer** due to these rules → **tell the user** which file/scope was not modified and why (e.g.
      last commit > 7 days or > 1 month without explicit request).
    - *(These thresholds are the file's last-commit date—how recently the file was changed—used only to decide whether
      to ask for confirmation before refactoring; they are not about API or version deprecation.)*
- **Do not break:** Base class, `#[RequestMapping]`, action routing attributes, `Action` suffix, namespace/location,
  return vs `Switon\Http\Transformer\NormalizeActionReturnTransformer`.
- **Thin controller (new code):** Prefer orchestration only; logic in service/repository. **Existing code:** many keep
  logic in controller; **do not refactor it out** unless user explicitly asks.
- **Simplify:** Method params for route/body; `$request->filters([...])` for list/search; return entity/Paginator with
  `JsonSerializable`.
- **Deduplicate:** Shared across actions → private method or service; across controllers → base Controller or shared
  service.
- **Extract when:** Long action, repeated blocks, or logic testable alone → service (or repository).
- **Don't:** Duplicate without need; constants for config (prefer config/component). Do not insist on moving existing
  in-controller logic unless user asks.

## CLI tools (router-assisted lookup)

- To find which controller/action handles a URL: **router:resolve**, then open the reported handler.

## Verify controller

When the user asks to verify or analyze a controller:

1. **Per controller:** Extends `App\Controller\Controller` (or `Switon\Http\Controller` if none); has
   `#[RequestMapping('/path')]`; name singular + `Controller`; namespace/location match (root vs Area).
2. **Actions:** Each public action has a routing attribute; name ends with `Action`; shared deps = `#[Autowired]`
   property.
3. **Return:** Matches `Switon\Http\Transformer\NormalizeActionReturnTransformer`; objects needing specific JSON
   implement
   `JsonSerializable`.
4. **Report:** OK → say briefly. Issues → list: wrong/missing base, RequestMapping, actions without routing attr,
   naming, fat controller, return/JSON.

## Source of truth

- Read `Switon\Http\Controller` (switon/http; in app: vendor/switon/http), app's `App\Controller\Controller` (create if
  missing), existing controllers. Implementation and PHPDoc are authoritative.
