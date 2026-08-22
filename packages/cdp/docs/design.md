# Design Rationale {#design-rationale}

`switon/cdp` keeps one local Chrome boundary for rendered pages and cookie-bearing HTTP. Call sites name a headless or headed service, then a page.

## See also {#see-also}

- [CDP Package](README.md) — usage, configuration, and the public contract
- [FAQ](faq.md#cdp-faq) — profiles, wait, and requests

---

## Core Problem {#core-problem}

Silent fetch needs a browser when the response depends on JavaScript or on cookies stored for a site. Callers also need a visible window for a person to sign in. One shared Chrome cannot hold two ports, and one profile cannot be open in two processes.

## Primary Goal {#primary-goal}

The package standardizes headless fetch first.

- **Benefit:** `open()`, `wait()`, `content()`, and `request()` cover a silent page read and an HTTP call from that profile.
- **Benefit:** `click()`, `fill()`, and `screenshot()` cover a basic look at the same page, including a visible window.
- **Benefit:** headed and headless configure `port` independently because they are two services.
- **Cost:** cookies follow the profile. A headed login is invisible to headless.

## Core Choice {#core-choice}

`HeadlessChromeInterface` and `HeadedChromeInterface` are the injected services. `ChromeInterface` is the shared contract and is not a container binding.

`open()` returns a `PageInterface` for that tab. The connection stays inside the implementation. `request()` is the one-shot path on headless: open, call, close. A caller that must wait for a selector keeps the `PageInterface` and calls `fetch()`. Click, fill, and screenshot use the same CSS selector as `wait()` and stay on that page.

`$origin` is the document opened before an API call so the tab's origin and cookies match the site.

## Trade-Off {#trade-off}

HTTP from a page is `fetch` with the profile cookies.

- **Benefit:** the site's cookies and a caller-supplied header go out together.
- **Cost:** the browser blocks cross-origin calls. An API needs `$origin`.

`wait()` returns when the selector matches and also when `timeout` elapses.

- **Benefit:** the caller can still read `content()` after a slow or blocked page.
- **Cost:** a missing element is visible in the HTML, not as an exception from `wait()`.

`click()` and `fill()` act on viewport pixels, so they are checked against what Chrome will actually hit.

- **Benefit:** an element behind an overlay throws `… is covered by …` instead of silently clicking the overlay. A real person's click never lands on a hidden node either.
- **Cost:** one extra `elementFromPoint()` in the same `Runtime.evaluate`, and callers must dismiss overlays rather than click through them.

Input goes to the active tab, so `click()` and `fill()` activate the tab inside its window. Raising the whole window is left to `activate()` and `screenshot()`.

- **Benefit:** an agent loop no longer pulls a visible window in front of the human on every step; a click costs one round trip instead of a process raise.
- **Cost:** when a human shares a window with attached headless work, the first agent click moves their tab behind — a Chrome constraint, stated in [Two Chromes](README.md#two-chromes), not a policy.

Process ownership is per debugging port.

- **Benefit:** one container resolves one launcher for both services, and closing the headless service leaves the headed browser — and anything it holds — running.
- **Cost:** `Launcher` keeps a port-to-pid map instead of one pid.

An element is addressed by `backendNodeId` (`#123`), not by a selector built from it.

- **Benefit:** the same address works in the top document, inside a shadow root, and inside a same-origin iframe. `DOM.getContentQuads` reports where the node lands on screen even when it lives in another document, so the click point needs no manual offset arithmetic.
- **Cost:** the address is opaque in a log, and it belongs to one document — it goes stale on navigation. `selector()` remains for readability and for humans reading a snapshot.

Diagnostics are collected from events that already flow through the session.

- **Benefit:** console output, uncaught exceptions, and failed requests cost no extra round trip; `Session` already buffers every `Runtime.*` and `Network.*` event for navigation.
- **Cost:** the buffer is bounded, and `diagnostics()` reads for a short window so a just-fired request can finish. A page that logs thousands of lines loses its oldest ones.

Dialogs are accepted automatically.

- **Benefit:** `alert()` cannot cost the caller its whole timeout budget. Without this, an open dialog blocks every later command on the tab.
- **Cost:** a page that asks "are you sure?" gets an answer it did not get from a person. A caller that needs the negative answer must handle the dialog itself, which the current API does not expose.

A cross-origin iframe is a separate target, discovered through `Target.setAutoAttach`.

- **Benefit:** its content is reachable, and `frames()` reports it, so "the page renders in an iframe" stops being an empty snapshot. Click and type reach into it as well, not only reads.
- **Cost:** two mechanisms for one concept: a same-origin frame is read by `frameId`, a cross-origin one by `sessionId`. Chrome does not merge them, and `Page.getFrameTree` omits the out-of-process frame entirely.

A cross-origin frame node is addressed as `#<owner>:<session>/<id>`, not as a selector.

- **Benefit:** one address covers reading, clicking, typing, and inspecting across a process boundary. The two facts it needs — which session owns the document, and where that document starts — come from a single `frames()` entry, so a caller never composes coordinates by hand.
- **Cost:** the form is positional and long, and it goes stale on navigation like every other node id. It exists because input is dispatched on the tab's main session while the node id is only meaningful inside the frame's own session; the two cannot be expressed as one plain id.

Input is always dispatched on the tab's main session, even for a node in another process.

- **Benefit:** the click point is composed once, in one viewport, and the browser routes it to whichever frame is painted there. No second input path, no synthetic events in the child.
- **Cost:** the frame must be visible and unoccluded; a frame scrolled out of the tab's viewport cannot be clicked into.

Navigation waits for `networkIdle`, not for `load`.

- **Benefit:** a single-page app mounts after `load`. Measured on a Vue app, the DOM at `networkIdle` already holds the rendered page, and the same navigation takes 2.0s instead of an open-ended guess. Nothing polls, so a fast page is not slowed down and a slow one is not missed.
- **Cost:** a page that mounts on a timer with no request behind it settles before it mounts — the caller uses `wait($selector)` there. A page with a permanent connection settles on `load` instead of waiting out the whole budget.

Snapshot order follows the viewport, and the line budget drops the tail.

- **Benefit:** a long page shows its visible half first instead of an arbitrary cut. Text below the fold is left out. `(offscreen)` marks a control a screenshot would not show.
- **Cost:** `DOMSnapshot.captureSnapshot` runs on each snapshot to read geometry — about 5ms for a few hundred nodes — and document order is no longer the reading order.

`assert()` retries the condition, not a delay.

- **Benefit:** "did the click work" becomes one call with an answer. Measured against a page that updates 1.2s after the click, the assertion returned `ok` at 1255ms — it followed the real state instead of guessing a wait.
- **Cost:** a caller that expects an exception on failure gets a value instead; a failed assertion has to be read. The retry costs one round trip per 100ms while the condition is false.

## Deliberate Simplicity {#deliberate-simplicity}

Launch, the DevTools websocket, and profile paths stay inside the package. Application code uses the two Chrome services and `PageInterface`.
