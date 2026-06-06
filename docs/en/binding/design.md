# Design Rationale {#design-rationale}

`switon/binding` standardizes **how argument values are built**, separate from **how the callable runs**.

## See also {#see-also}

- [Binding Package](README.md) — usage, boundaries, and configuration
- [FAQ](faq.md#binding-faq) — common questions and troubleshooting

---

## Why This Exists {#why-this-exists}

Entry code usually picks the target **method** before it has final **argument** values.

**Binding** standardizes three recurring decisions:

- **Type-owned rules:** construction through **`#[ResolvedBy(...)]`**
- **Service-style objects:** **container** lookup when nothing else resolves
- **Scalars:** **`Switon\Core\InputInterface`**

## Separate From Invoker {#separate-invoker}

- **`switon/binding`:** produces the resolved **argument list**
- **`switon/invoking` (`InvokerInterface`):** runs the callable with that list

**Benefit:** sourcing, hydration, and validation stay separate from invocation policy.

## Scalars Versus Objects {#scalars-objects-separate}

Scalars and objects use **different** resolution paths.

**Benefit:** raw input cannot accidentally leak into object construction without an explicit binding step.

## Type-Owned Resolution {#type-owned-resolution}

Some types are stable enough to **own** their construction contract.

Example: **`Page`**

- The rule sits on the type through **`#[ResolvedBy(...)]`**
- Call sites do **not** repeat resolver wiring on each parameter

## Typed Input Hydration {#input-lives-here}

Typed input binding is a **reusable** mechanism shared by HTTP (and similar) layers:

- Transport picks the **payload**
- Binding **hydrates and validates** the typed object

## Hooks {#why-hooks-exist}

Cross-cutting parameter transforms should **not** require copy-pasted dispatch glue.

Use **pre-fill** and **final-adjustment hooks** only when behavior is broader than one concrete resolver.

## Input Ownership {#input-interface-outside}

Binding **reads** `Switon\Core\InputInterface` but does **not** define transports.

HTTP, CLI, and custom fronts can share the **same binding** because the contract stays neutral.
