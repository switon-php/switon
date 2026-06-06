# Filter (request lifecycle)

Request-wide concerns (logging, CORS, headers); not action-specific. Return normalization belongs to transformers.
Business → [listener](references/listener.md).

## 1. Where it runs

- HTTP request lifecycle via event listeners (no separate pipeline). Events: `Switon\Http\Event\*`. Common:
  `RequestBegin`, `HeadersSending`, `RequestEnd`; errors: `RequestFailed`. List: `event:classes` filter
  `Switon\\Http\\Event\\`.
- **Short-circuit:** Set response on event, throw `Switon\Core\StopFlow` → response sent, remaining listeners skipped.

## 2. Implementing

- `#[Switon\Core\Attribute\EventListener]` on method, one parameter (event type). Same attribute/signature
  as [listener](references/listener.md). Parameter can be a **union** (one registration per event type); same as
  listener.
- Role: non-business request-wide lifecycle behavior (logging, CORS, headers). Business → listener. Optional:
  `Switon\Core\Event\ObservabilityProbe` for monitoring-only (e.g. `AccessLogFilter`).

## 3. State

- Per-request: `Switon\Core\ContextAware` + Context. `RequestInterface::setAttribute()` allowed but not
  preferred.

## 4. Location and naming

- Framework: `{Package}\Filter\*` (package root, e.g. `Switon\Http\Filter\*`). App: under `@app/Filter/` and
  `@app/Areas/*/Filter/` with suffix `*Filter`; discovery uses explicit `glob => class-pattern` mappings such as
  `@app/Filter/*Filter.php` => `App\Filter\*Filter`. Packages: `extra.switon.filters`. Config may
  reference any FQCN.

## 5. Discovery

- Packages: `composer.json` `extra.switon.filters` (FQCN list). App: `FilterDiscovery::$files` mappings (default
  `@app/Filter/*Filter.php` => `App\Filter\*Filter`,
  `@app/Areas/*/Filter/*Filter.php` => `App\Areas\*\Filter\*Filter`) via `ClassScannerInterface`.
  Merged list via `Switon\Http\FilterDiscovery::discover()`; **enabled + order** come from config (§6), not discovery.
  CLI: `filter:list`.

## 6. Config (enable/disable)

- **File:** `config/filter.php`. **Top-level key:** `Switon\Http\RequestHandlerInterface` →
  `filters` => [ name => FQCN ]; `null` = disable; array order = execution order. **Read by:** `RequestHandler`’s
  `#[Autowired]` `filters` property (Injector merges with defaults).

## 7. See also

- [interceptor](references/interceptor.md) (action-scoped) · [listener](references/listener.md) (event-driven)
