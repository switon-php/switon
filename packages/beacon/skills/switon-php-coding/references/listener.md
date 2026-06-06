# Listener (subscribe / inspect)

Use when the user asks to add or change a listener, or to find which code reacts to events.

## 1. Listener classes

- **Location:** Listener classes live under `App\Listener` (files under `@app/Listener/`; areas may also use
  `@app/Areas/*/Listener/`).
- **Signature:** Public method with `#[Switon\Core\Attribute\EventListener]` and **exactly one parameter** (the event
  type). Prefer method names like `on<EventName>`.
- **Multiple events:** Parameter type can be a **union** (e.g. `UserCreated|OrderPlaced`) — one listener registered per
  event type.
- **Wildcard:** Parameter type `object` subscribes to all events.
- **State:** For per-request state, use `Switon\Core\ContextAware` + a Context class; do not store state on
  listener properties.
- **Observability vs business:** Implement `Switon\Core\Event\ObservabilityProbe` on monitoring-only listeners. Business
  listeners should not implement it; the dispatcher tracks call counts separately in `EventDispatcherContext`.

## 2. Finding events for listeners

- **event:classes**, **event:class**

## 3. Finding listeners (for diagnostics)

- **event:listeners**

## 4. See also

- **Event** | Define and categorize events, dispatch rules | [references/event.md](references/event.md)
