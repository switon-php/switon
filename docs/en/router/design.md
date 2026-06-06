# Design Rationale {#design-rationale}

`switon/router` keeps discovery explicit, path composition predictable, and prefix handling separate from route definitions.

## See also {#see-also}

- [Router Package](README.md) — usage, registration, and core routing rules
- [FAQ](faq.md#router-faq) — short answers for common routing questions

---

## Core Problem {#core-problem}

Route tables get hard to explain when discovery, prefixes, and matching rules live in different places. Router standardizes that work so a route can be traced from controller attribute to final match.

## Primary Goal {#primary-goal}

Router standardizes one route table first, then layers discovery, prefix handling, and diagnostics on top:

- explicit discovery keeps the table predictable
- prefix belongs to matching, not to every stored route
- literal routes stay fast, while dynamic routes pay the regex cost only when needed

## Trade-Off {#trade-off}

Router does not model route registration as an implicit scan of every public class.

- **Benefit:** discovery stays visible.
- **Benefit:** route behavior is easier to inspect.
- **Cost:** a controller needs `#[RequestMapping]` before it becomes routable.

## Deliberate Simplicity {#deliberate-simplicity}

`router:list`, `router:resolve`, and `router:prefix` keep route diagnosis close to the package boundary instead of pushing it into temporary debug code.
