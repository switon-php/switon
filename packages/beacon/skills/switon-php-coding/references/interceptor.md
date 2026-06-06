# Interceptor (action-scoped AOP)

Use when the user asks to add/change interceptors, or to wrap controller actions with pre/post/exception logic.

## 1. Where interceptors run

- Interceptors apply only when a method is invoked via `Switon\Invoking\InvokerInterface::invoke()` with
  `$useInterceptor = true`.
- Common invoke points: controller actions and CLI command actions.

## 2. Implementing an interceptor

- Create an attribute class that implements `Switon\Invocation\Attribute\InterceptorInterface` and add
  `#[Attribute(Attribute::TARGET_METHOD)]`.
- For HTTP controller actions, extend `Switon\Invocation\Attribute\Interceptor` and inject
  `Switon\Http\RequestInterface` / `Switon\Http\ResponseInterface` with `#[Autowired]` when needed.
- make() per invocation; controller actions ≈ one per request.
- **Shared state:** `Switon\Http\RequestInterface::setAttribute()` or another request-scoped service.

## 3. Location and naming

- App interceptors live under `App\Interceptors\...`.
- Area-specific interceptors live under `App\Areas\<Area>\Interceptors\...`.
- Name classes with an `*Interceptor` suffix for clarity.

## 4. Applying to actions

- Interceptors are method-level attributes placed on controller action methods.

## 5. Interceptor vs listener/filter

- **Scope:** interceptor = action-scoped; listener = event-scoped (use for request lifecycle hooks); filter =
  request-scoped (often implemented as lifecycle listeners).
- **Compared to a typical `$next()` stack:** Switon splits concerns into lifecycle listeners (more granular phases) and
  interceptors (action‑level control).
