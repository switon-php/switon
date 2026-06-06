# Context Internals {#context-internals}

Traces `ContextManager` and coroutine-local state when you need implementation rationale—not the everyday API entry.

## When to read this folder {#scope}

This folder is useful when you want to understand:

- why direct `Coroutine::getContext()` usage is not enough for framework-level state management
- why Switon centralizes context storage and discovery in `ContextManager`
- how inheritance, isolation, and runtime branching emerged from concrete failure modes

---

## Available notes {#available-notes}

- [Evolution walkthrough](evolution.md#evolution) — step-by-step progression from naive coroutine-local storage to the production
  design

---

## Next {#next}

- [Context Package](../README.md) — public usage, isolation modes, and common pitfalls
- [Design Rationale](../design.md#design-rationale) — stable storage and lifecycle behavior
