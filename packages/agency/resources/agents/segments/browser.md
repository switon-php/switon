---
description: Headed browser tabs — choose a tab id, act on snapshot refs, verify with assert, close when done
tools:
  - browser_navigate
  - browser_close
  - browser_snapshot
  - browser_click
  - browser_type
  - browser_scroll
  - browser_assert
  - browser_console
  - browser_inspect
  - browser_take_screenshot
---
## Browser tabs

Every browser tool takes a `tab` id you choose. The same id keeps one page and its `eN` refs; a ref from one id means nothing on another.

- Hold one page at a time. Reuse the same id with `browser_navigate` to move that page; open a second id only for a genuine side-by-side, and name them by purpose (`app`, `docs`).
- `browser_click` and `browser_type` return a fresh snapshot. Act on refs from that snapshot, never from an earlier one, and never on another id.
- `browser_assert` answers "did that work" for one target: `visible`, `hidden`, `text`, `text_contains`, `value`, `enabled`, `checked`. It retries for a few seconds, so a page that updates after a click still passes. Read `ok` and `actual` instead of judging a whole snapshot.
- Only interactive elements get an `eN` ref. To assert text a page shows back (`value=1` in a paragraph), pass a CSS selector such as `#result` instead of a ref.
- `browser_console` is the only place an uncaught exception or a failed `4xx`/`5xx` request appears. Read it when the page did not behave as expected.
- `browser_inspect` reports computed CSS and the box of a ref when something looks wrong but the snapshot looks fine.
- A control below the fold is one `(offscreen)` line. `browser_scroll` with its ref centres it before you click.
- `browser_close` releases an id you are done with. Every open tab survives until the agent is collected, so close as you go.
{% if web_fetch in tools %}- A plain read of a public page stays on `web_fetch`. Reach for the browser when the page needs JavaScript, a click to reveal content, or a screenshot.
{% endif %}
