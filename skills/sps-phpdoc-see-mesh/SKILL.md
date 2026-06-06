---
name: sps-phpdoc-see-mesh
description: Use for Switon monorepo @see-mesh work. Ensure PHPDoc @see tags form a discoverability mesh across framework packages so AI can reach related code quickly. Use when the user wants to verify @see targets exist, check @see correctness, or audit @see links.
---

# PHPDoc @see Mesh (Switon)

## Verify @see targets exist (mechanical)

Run from **repository root** (uses `packages/*/composer.json` PSR-4 only; no full `class_exists` so optional/broken autoload will not fatally load unrelated code):

```bash
php skills/sps-phpdoc-see-mesh/scripts/validate-see-exists.php [scan-root]
```

- **Default:** `scan-root` is `packages`. Only **`\\Switon\\...`** tags are checked; `https?://` URLs, `debug_backtrace()`, and non-`Switon` PSR types are **skipped** (out of scope for this check).
- **`--all`:** also report unparseable tags and try every token (noisy: PSR interfaces in `vendor`, bare `route()`-style names, etc.).
- **Swoole:** `\\Swoole\\...` is skipped (extension, not in tree).
- **Pass:** exit `0` and `OK: all Switon @see targets...` — every in-scope tag maps to a file under `packages/`, and `::method` / `::$prop` when present match the file.

Narrower scan: `php .../validate-see-exists.php packages/core/src`.

## Goal

@see builds **semantic connections** between code elements: “to understand this, also see that.” From any class, an AI can reach interface, implementation, and main collaborators in 1–2 hops. **Why it matters for AI:** explicit “related reading” (context), knowledge-graph edges (intent over guesswork), fewer hallucinations, better RAG retrieval.

**Valid targets:** Class/interface/trait (FQCN); method/property (`\FQCN::method()`, `\FQCN::$prop`); constant; URL for specs; config key / debug command / log category when they are the real contract (see Format below). Switon: prefer FQCN for code; file path only when no class exists.

**Semantic axes:** Vertical (interface → impl, exception hierarchy); Horizontal (event ↔ listener, interface ↔ main consumers); Depth (config → class that reads it, not raw path).

## Connection types (what to link)

| Type | Example |
|------|---------|
| Contract ↔ implementation | Interface @see default impl (+ alternative if relevant) |
| Implementation → contract | Impl @see interface, main DTO |
| Exception cause chain | Exception @see method that throws it, parent, related event |
| Event flow | Event @see dispatcher/trigger, related events, one typical listener |
| Abstract ↔ strategy | Abstract class @see official adapters (e.g. GdAdapter, ImagickAdapter) |
| Replacement / migration | @deprecated @see replacement class (and migration URL if any) |
| Config | @see class that reads config (e.g. \Switon\Kernel\Config), not config/file.php |

**Switon cross-package:** Impl in package A @see interface in B; interface in B @see default impl in A. Event @see dispatcher; Exception @see handler when obvious. No need to @see every impl — only default + main consumers. **Focus:** Core as hub (upper packages @see Core interfaces); event chain = trigger → event → typical listener; each exception @see 2–3 methods that throw it; abstract classes @see all official adapters.

## Format and constraints

**Format:** `\Namespace\ClassName` or `\FQCN::method()`; no raw `src/...`. First mention of a type in a docblock: FQCN. **Allowed non-FQCN as contract:** config keys, debug commands (e.g. `bin/console debug:db:transactions`), log categories.

**DO:** FQSEN — `\FQCN::method()`, `\FQCN::$property`, `\FQCN` for class/interface. **DON'T:** Relative ref without namespace; doc-only URL without a code @see; circular A→B→A.

**Optional (use only when the artifact exists):** RFC/PSR/spec for compliance; ticket/CVE/benchmark when your process uses them. Code @see stays FQCN.

## Rules

1. **Format**: One FQCN per @see (optional `::method()` for method-level); no trailing prose. Prefer `Class::method()` **on that method’s docblock** when the link is about a specific method’s contract; at **class level** prefer the type `\Class` only.

2. **No duplicate edges:** In the **same** docblock, do not list both `\FQCN` and `\FQCN::method()`. Pick one hop: class doc → type; method doc → `::method()` when needed. This cuts redundant graph edges without losing discoverability.

3. **Verify**: Every @see must point to an **existing** FQCN in the codebase (no typos).

4. **Per-class targets** (directly used only). Counts are guidelines.

   | Class type | @see (guideline) | Must include |
   |------------|-------------------|--------------|
   | Interface | 3–4 | Default impl, **main consumers**, main DTO, key exception/event |
   | Implementation | 2–3 | Interface, main DTO, factory if any |
   | Exception | 1–2 | Thrower, parent |
   | Event | 2–3 | Emitter, related events, listener interface |
   | Attribute | ≥2 | One usage example, **focused method** that processes it (prefer `Class::method()`) |
   | DTO | 1–2 | Creator or main consumer |

   Related events: pair or sequence (RequestBegin ↔ RequestEnd; DbConnecting → DbConnected).

5. **Bidirectional**: Impl @see interface + DTO; interface @see default impl(s) + payload/events.

6. **Do not @see**: Internal utils; every single impl (interface lists them); indirect deps (A→B→C ⇒ A does not @see C). Test classes: only allow @see to a **specific test method** as behavior contract. De facto single impl (e.g. Container for ContainerInterface) may be @see from interface.

7. **Cross-component**: Mesh **within** component first. For other packages: only **direct** collaborators (injected interface, event listened/dispatched, exception thrown). Do not @see Core\Attribute\*, infra helpers, transitive deps. @see is doc-only, not a code dependency.

8. **Attribute processing (consumer side, optional)**: When convenient, in the class that uses an attribute, extract a **focused method** for “read this attribute and act on it” (e.g. `parseLevel()`, `resolveFromAttribute()`). Then the Attribute’s @see can point to that method so AI jumps to the single place that handles it. Not required. No need for a dedicated global “annotation processor” class.

## Cross-package audit (find gaps)

- Class **implements** or **extends** something in another package → that class MUST have @see to the interface/base. Interface/base SHOULD @see default impl when it lives in another package.
- Same for multiple packages implementing one interface: interface @see impl in each package; each impl @see the interface.
- Examples: FactoryInterface (Di, Db, Redis), Kernel (Http, Cli), LookupInterface (authz, rbac), Entity/Repository (Orm, Rbac).

## Quick check

- From concrete class → can you reach interface and main DTO via @see?
- From interface → at least one impl and main payload/event?
- Cross-package implements/extends → subclass @see parent; parent @see default impl?

## Example (log)

LoggerInterface: Logger, LogEntry, AppenderInterface, LoggerLogged, Level, Categorized. Logger: LoggerInterface, Level, LogEntry. FormatterInterface: Formatter, LogEntry. AppenderInterface: FileAppender, RedisAppender, …, LogEntry, FormatterInterface. From any of these, the rest is one hop away.
