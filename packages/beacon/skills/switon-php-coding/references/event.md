# Event (define / dispatch / categorize)

## 1. Events

- **One class per event.**
- Payload in public properties (often `readonly`).
- Recommended naming: `*ing` for before/during, `*ed` for after.
- **Locations:**
    - App events: `App\Event\...` (including `App\Areas\Foo\Event\...`).
    - Framework events: files under `{Package}/Event/` in each package.

### Log category (event class PHPDoc)

- **What it is:** Structured log code for the event (e.g. `app.user.created`), used by `Switon\Eventing\EventLogger`
  when logging.
- **How to get it:** `event:category` → use returned `category`.
- **When adding events:** In the event class PHPDoc add `Log category: <code>…</code>`.

**Pattern examples (for rough shape only):**

- `App\Event\User\LoggedIn` → `app.user.logged.in`
- `App\Event\Users\UserBanned` → `app.users.banned`

## 2. Dispatching from app code

- Inject `Psr\EventDispatcher\EventDispatcherInterface`, call `dispatch(object $event)`. No return value contract;
  listeners are side effects.
- For high-frequency or internal-only events, mark event class with `Switon\Core\Event\EventSilent` to skip automatic
  logging.

## 3. Tools

- **event:by-code**, **event:category**, **event:class**, **event:classes**

## 4. See also

- **Listener** | Subscribe to events, write and diagnose listeners | [listener.md](references/listener.md)

