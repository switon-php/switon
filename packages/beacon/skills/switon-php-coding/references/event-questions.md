# Event reference — questions

**Generate:** switon-meta-skill::questions-author. **Verify:** switon-meta-skill::verify-reference.

---

**1. Event classes and naming**
1.1. How many classes should represent one logical event, and where should the event payload normally be stored?
1.2. When defining a new event that represents an ongoing action vs a completed action, what naming suffixes are
recommended?
1.3. For an application event in the default app namespace, what namespace prefix pattern is normally used for event
classes, including area-specific events?
1.4. If conventions recommend using `*ing` for before/during and `*ed` for after, but you see an existing event named
`UserRegistered`, what should you do before renaming or keeping the current name?

**2. Log categories**
2.1. If you need to know the current log category used at runtime for a given event class `App\Event\User\UserCreated`,
which `event:*` tool should you run, and which field from its JSON output should you read?
2.2. When adding a new event class, what exact PHPDoc line should you add to document its log category?
2.3. Based on the existing patterns in the project, how would `App\Event\Orders\OrderCancelled` most likely be converted
into a log category string (before verifying with tools)?
2.4. A user gives you a log category code like `app.order.placed` and asks "which event classes could emit this?". Which
`event:*` tool should you use, and what does it return?
2.5. When updating an existing event to change its category, what checks should you perform so that PHPDoc, runtime
category, and any external usage (logs, dashboards) remain consistent?
2.6. Suppose you add a new event class under `App\Areas\Admin\Event\UserPromoted`. Outline how you would (a) decide on
its log category, (b) update PHPDoc, and (c) verify the category once the code is in place.

**3. Dispatching and logging**
3.1. How should you dispatch an event object from application code, and what is the return value contract of this call?
3.2. For a high-frequency internal event that you do not want to be logged by the built-in event logger, which interface
or marker should the event class implement?

**4. Tools for events and listeners**
4.1. If a user asks "what events exist in this app and framework?", which `event:*` tool can you use for listing them,
and how can you narrow the list?
4.2. When you need full details for a specific event (file path, category, properties), which `event:*` tool should you
use, and what top-level fields does it return?
4.3. If you need to know which listeners are currently attached to a given event, which `event:*` tool should you run
and how are listeners represented in its JSON output?
4.4. How can you use `event:classes` and `event:class` together to verify that every event class documented with a
`Log category: <code>…</code>` line in PHPDoc is actually using that category at runtime?

**5. Listeners**
5.1. Where should you place new listener classes in the app namespace, and how does this map to filesystem paths using
aliases?
5.2. When writing a new listener method, what attribute and method signature rules must you follow (visibility, number
of parameters, and parameter type)?
5.3. For a listener that should react to multiple different events, what change should you make to the parameter type,
and how does the framework treat this?
5.4. If you want one listener to observe every event for metrics or logging, what parameter type should you use on the
`#[EventListener]` method?
5.5. When a listener needs per-request state across multiple method calls, which interface should it implement, and
where should you store that state instead of on the listener properties?
5.6. How do you distinguish "business" listeners from "observability" listeners in code, and what effect does this have
on how the dispatcher tracks call counts?
5.7. How should you name listener methods for clarity, and why might this be preferable to generic names like `handle()`
from an AI perspective?

**6. Flow / multi-class**
6.1. Given only the current project (no extra skills), describe a safe sequence of steps (including which tools and
reads you may use) to: (a) find all events related to HTTP requests, and (b) find their listeners.
6.2. Which parts of the event and listener behaviour are configuration-driven (e.g. scan paths, logging enable/disable),
and which parts must always be expressed directly in code (class names, attributes, method signatures)?

**7. Compatibility and legacy events**
7.1. An existing event class `App\Event\User\UserRegistered` is already used by multiple logs and dashboards, but its
name does not match current naming recommendations. A user asks "can we rename it to `UserSignedUp` and unify the log
category?". Without breaking compatibility, how do you respond, and what migration strategy (if any) do you suggest?
7.2. For an existing event, the PHPDoc says `Log category: app.user.created`, but `event:category` shows the runtime
category is `app.user.signup.completed`. A user asks you to "make them consistent". Do you lean towards changing the
PHPDoc, changing the runtime category, or leaving both as-is? List the checks you would perform before deciding.
7.3. The logging platform already has a large amount of historical data under `app.order.placed`. Product now wants to
distinguish between "pre-order" and "confirmed order" as separate events. How would you design new event classes and log
categories, and what would you do with the existing event and category?

**8. Tool workflows and diagnostics**
8.1. A user says "I suspect some event classes are never dispatched". You are allowed to use only `event:*` tools and
reading code (no code changes or added logging). Describe a cautious diagnostic workflow, noting which tools you would
use and what you would look for in their output.
8.2. You want to verify that every event class with a `Log category: <code>…</code>` line in its PHPDoc actually uses
the same category at runtime. Using only `event:*` tools, outline a high-level process (not necessarily a full script)
showing in what order you would call which commands and how you would compare results.
8.3. A listener appears not to react to the `UserLoggedIn` event. You have already confirmed that the listener is in the
correct namespace and has the `#[EventListener]` attribute. Using `event:*` tools, propose a short "investigation chain"
that helps you decide whether the problem is that the event is never dispatched, the listener is not registered, or the
event/class name is incorrect.

**9. Framework integration and migration**
9.1. A user coming from Laravel asks "where is your equivalent of `EventServiceProvider` and how do I register listeners
there?". Explain how Switon discovers events and listeners without a central provider class, and what they should do
instead when adding new listeners.
9.2. In another framework, the user is used to firing events via static facades (e.g. `Event::dispatch(...)`). They now
ask for "the Switon way" to dispatch events from controllers and services. Describe how to do this using dependency
injection and `Psr\EventDispatcher\EventDispatcherInterface`, and why this is preferred.
9.3. A migrated codebase still contains old "string event names" (e.g. `'user.registered'`) from a previous framework.
How would you guide the user to migrate these to proper event classes and log categories in Switon, while keeping the
old strings discoverable during the transition?
9.4. The user previously relied on a global "event subscriber" system where listeners are configured in a single array
or config file. They now want to know how to express the same intent in Switon. Explain how attribute-based listeners
replace central config, and how you would help them map a few concrete examples across.

**10. Logging, observability, and async concerns**
10.1. A user wants to "log fewer events to keep storage costs down" but also complains that metrics dashboards built on
`ObservabilityProbe` listeners are now incomplete. Explain the different roles of `EventSilent`, log categories, and
observability listeners, and propose a way to reduce log volume without breaking observability.
10.2. In a previous framework, events were implicitly queued and handled asynchronously. In Switon, the user expects the
same but currently all listeners run synchronously. Without inventing new infrastructure, how would you (a) explain the
current synchronous behaviour, and (b) help them introduce an explicit async boundary while keeping event classes and
listeners mostly unchanged?

**11. User stories without explicit "event" wording**
11.1. A user says "I want to run some code every time a user logs in, without touching the login controller". Based on
the existing references, which mechanisms should you consider, and how would you roughly implement this?
11.2. Another user asks "where can I plug in a hook that sees every successful HTTP response for analytics?". Describe
how you would approach this using the available event and listener tooling, even though the user never mentioned events
by name.
11.3. Someone requests "a way to centralise all important domain actions for auditing, ideally in one place". How would
you interpret this in terms of Switon's event and listener model, and what concrete structures (namespaces, classes,
attributes) would you recommend creating?
