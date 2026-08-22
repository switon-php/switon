# Browser FAQ {#browser-faq}

Short answers for snapshot refs, scrolling, and how this package relates to CDP.

> **Prerequisites:** [Browser Package](README.md)

## Getting Started {#getting-started}

### Which type do I inject? {#which-type}

Agent tools resolve a tab through `HeadedBrowsersInterface::tab($agent, $tab)`. `open($agent, $tab)` creates that name. The agent chooses `$tab`; the same string keeps that page, and two agents may reuse the same string for different pages. `browser_close` drops one id. When the agent is collected, its remaining tabs close. Inject `HeadedBrowserInterface` only for a single PHP caller that is not sharing the process with other agents. Inject `Switon\Cdp\HeadlessChromeInterface` for a silent page read or cookie-bearing HTTP. See [Quick Start](README.md#quick-start).

### How do I click something the snapshot listed? {#click-ref}

Call `browser_snapshot` (or use the snapshot `browser_navigate` already returned) with the same `tab` id, then `browser_click` with that `eN`. Do not reuse a ref from an earlier snapshot, and do not use it on a different tab. See [Snapshot refs](README.md#snapshot-refs).

## Behavior {#behavior}

### Why is a paragraph missing from the snapshot? {#missing-text}

It is below the fold, or it is `display:none`. Offscreen text is counted in the header, not listed. Hidden nodes are absent from the accessibility tree. Scroll, or use `browser_inspect` with a CSS-less ref you already have. See [Below the fold](README.md#below-the-fold).

### What does `(offscreen)` mean? {#offscreen}

That control is outside the viewport. The line is still clickable; `click()` scrolls to it. See [Below the fold](README.md#below-the-fold).

### Why did the page look wrong when the snapshot looked fine? {#console}

Read `browser_console`. An uncaught exception and an HTTP `500` never appear in the accessibility tree. See [Tools](README.md#tools).

### Is there a JavaScript tool? {#evaluate}

No. PHP can call `HeadedBrowserInterface::evaluate()`. The agent tools stop at snapshot, click, type, scroll, inspect, and assert.

### Do dialogs block the tab? {#dialogs}

No. `switon/cdp` accepts `alert()` and answers `prompt()` with an empty string. There is no tool to dismiss or refuse a dialog.

## Next {#next}

- [Browser Package](README.md)
- [Design Rationale](design.md#design-rationale)
