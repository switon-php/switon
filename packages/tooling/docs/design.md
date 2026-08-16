# Design Rationale {#design-rationale}

`switon/tooling` keeps tool lookup in one catalog and runs one call at a time.

## See also {#see-also}

- [Tooling Package](README.md) — usage and registration
- [FAQ](faq.md#tooling-faq) — registration and calls

---

## Core Problem {#core-problem}

Tool setup drifts once an app has more than one host action: hard-coded shells, file helpers that disagree on paths, and a loop that also owns the tool list.

## Primary Goal {#primary-goal}

Name a tool, call it, and leave the agent loop outside this package.

- **Benefit:** one catalog. Built-ins are already named.
- **Cost:** a package cannot replace a built-in through `extra.switon.tools`. The app config does that.

## Core Choice {#core-choice}

The built-in names start in the catalog. Boot reads `extra.switon.tools` and keeps the first declaration of a name that is still open. App config on `tools` replaces or drops a built-in. A call checks arguments and returns a result, including when the tool fails.

A long result is stored beside the thread instead of being pushed back in full.
