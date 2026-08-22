# Design Rationale {#design-rationale}

`switon/browser` is the agent session on a headed Chrome tab. It does not replace `switon/cdp`.

## See also {#see-also}

- [Browser Package](README.md) — usage and tools
- [FAQ](faq.md#browser-faq) — refs, offscreen lines, and CDP

---

## Core Problem {#core-problem}

An agent needs to see a page and act on it without inventing CSS selectors. The accessibility tree already names controls. CDP can click a `backendNodeId`. The missing piece is a stable, short id in the text the model reads.

## Primary Goal {#primary-goal}

Standardize named headed tabs and a snapshot that agents can act on.

- **Benefit:** `eN` refs reach shadow DOM and iframes, where a reconstructed selector does not.
- **Benefit:** click and type return a new snapshot, so the model does not keep stale ids.
- **Cost:** this package depends on `switon/ai` and `switon/tooling`. Silent fetch must not.

## Core Choice {#core-choice}

`HeadedBrowsersInterface` keys tabs by the live agent object (`WeakMap`) and the id that agent chose. Each `HeadedBrowserInterface` still owns one tab and its `eN` map. Tools pass that id; `navigate` is what opens a new name. CDP owns Chrome, coordinates, and HTTP. The headed window is shared; the snapshot map is not. A collected agent drops every tab it still holds.

Text below the fold is omitted; offscreen controls stay as one line. Prose past a fixed character budget folds into one count line. Controls never fold. Those cuts are for token cost on long chats, not for a different addressing model.

## Trade-Off {#trade-off}

The snapshot is an accessibility tree, not pixels.

- **Benefit:** the model gets names and roles it can click.
- **Cost:** colour, overlap, and failed network calls need screenshot and console.

Dialogs are auto-accepted in CDP.

- **Benefit:** an `alert()` cannot stall every later command.
- **Cost:** a confirm the human would refuse is accepted.

## Deliberate Simplicity {#deliberate-simplicity}

There is no `browser_evaluate` tool, no hover, no drag, and no file upload. PHP can still `evaluate()` on the same tab. Extra tools would duplicate CDP without a snapshot contract.
