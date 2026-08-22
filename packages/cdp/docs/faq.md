# CDP FAQ {#cdp-faq}

Short answers for headless fetch, profiles, and timeouts.

> **Prerequisites:** [CDP Package](README.md)

## Getting Started {#getting-started}

### Which type do I inject? {#which-type}

Inject `HeadlessChromeInterface` for silent fetch. Inject `HeadedChromeInterface` for a visible window. `open()` returns a `PageInterface`. See [Quick Start](README.md#quick-start).

### How do I read a page after an element appears? {#read-after-element}

Call `open()`, then `wait($selector)`, then `content()`. Close the page when the read is done. See [Page](README.md#page).

### How do I click, type, and capture the screen? {#debug-page}

Keep the page from `open()`. Call `click($selector)`, `fill($selector, $text)`, and `screenshot()`. `screenshot()` returns PNG bytes. Use a visible window when you need to watch the same page. See [Page](README.md#page).

### How do I send POST with the site's cookies? {#post-with-cookies}

Call `request()` with the method, the API URL, headers, body, and `$origin` set to a document on that site. See [Requests](README.md#requests).

## Profiles {#profiles}

### Why is a headed login missing from headless? {#login-profile}

Each service has its own profile. Headless reads `~/.switon/chrome/headless` on port `59222`. Headed writes `~/.switon/chrome/headed` on port `59223`. A login in the headed window stays there.

Open a visible Chrome on the headless profile and port `59222`. Headless attaches when that port is open and the window has a page you opened. See [Two Chromes](README.md#two-chromes).

Attaching reuses cookies; it does not keep your tab in front for long. Chrome delivers input to the active tab only, so the first `click()` or `fill()` from the agent activates its own tab and yours goes behind. Use a separate window for interactive work.

### What happens if something is already listening? {#already-listening}

Headless attaches to an existing Chrome on its port when that window holds a page you opened — the login-reuse path. A window holding only a blank tab is closed and launched again as headless, so a silent fetch never runs against an idle window.

## Behavior {#behavior}

### Does `wait()` fail when the element never appears? {#wait-timeout}

It returns either way. The selector matched, or `timeout` elapsed. `content()` is still available, including a challenge page that never showed the selector. Check the HTML when the element must be present. See [Page](README.md#page).

### Does `request('GET', $url)` wait for a selector? {#request-get}

No. A plain document `GET` opens the URL and returns the HTML with status `200`. Use `open()`, `wait()`, and `content()` when the node must appear first. See [Requests](README.md#requests).

### Why does `fetch` fail for some URLs? {#fetch-origin}

The call runs inside the open page, with that page's cookies. A cross-origin URL is blocked. Pass `$origin` as the site document, or call `fetch()` on a page already open there. `Referer` is only a header, and it applies to that one `fetch()`; the tab does not keep it.

### Why does the page look wrong but the snapshot looks fine? {#why-wrong}

Read `diagnostics()`. An uncaught exception and an HTTP `404`/`500` never appear in a snapshot — the document loaded, the script did not finish. See [Diagnosing a page](README.md#diagnosing).

### Why did `evaluate()` throw `Page script failed`? {#evaluate-throws}

Because the page threw. The exception is re-raised with the page's message and stack, which is usually the fastest explanation for a page that stopped working.

### Why is `display: none` not in a snapshot? {#hidden-elements}

The accessibility tree leaves out what a screen reader would. An element hidden with `display:none`, `visibility:hidden`, or `hidden` is absent from it. `inspect()` reports it anyway, addressed by its CSS selector.

### How do I know a click worked? {#assert}

Call `assert($path, 'text', 'Saved')` — or `visible`, `value`, `enabled`, `checked`, `hidden`, `text_contains`. It keeps checking the condition for a few seconds, so a page that updates late still passes, and a failure returns `ok: false` with what the page actually showed instead of throwing. See [Checking that an action worked](README.md#asserting).

### Why does an assertion fail immediately on a slow element? {#assert-timeout}

Raise its `timeout` argument. The default is 5 seconds; the condition is re-read every 100ms, so a page that lands at 8 seconds needs `timeout: 10`.

### Why do some snapshot lines say `(offscreen)`? {#offscreen}

Because that control is below the fold: a person would have to scroll to see it, and a screenshot does not show it. The line is still usable — `click()` scrolls to it first. Text below the fold is not listed; `scroll()` brings it into the next snapshot. See [Long pages and scrolling](README.md#long-pages).

### Why is a snapshot cut off? {#truncated}

A snapshot has a line budget, and the last line says how many entries were dropped. Lines are ordered viewport-first, so the visible part of the page is what survives. Narrow the page (a filter, a smaller list) or scroll to see more.

### Why is my button inside a shadow root unclickable? {#shadow-dom}

A CSS selector cannot cross a shadow boundary, so `click('#save')` finds nothing even though the snapshot shows it. Use the node id: `click('#' . $backendNodeId)`. See [Addressing an element](README.md#addressing).

### Why does an iframe look empty? {#iframe}

Its content belongs to another document. `frames()` lists them; `frameSnapshot($frame)` reads either kind. To act inside a cross-origin frame, address the node as `#<owner>:<session>/<id>`. See [Iframes](README.md#iframes).

### Why did `click()` refuse a selector that exists? {#click-covered}

Because something is painted over its centre point. `click()` and `fill()` check `document.elementFromPoint()` and throw `… is covered by …` rather than clicking whatever is on top. Wait for the overlay to close, or dismiss it first.

### Why does the visible window no longer jump to the front on every click? {#no-raise}

It should not. `click()`, `fill()`, and key presses activate the tab inside its window, which is what Chrome's input pipeline needs, and leave the operating system's frontmost application alone. `activate()` and `screenshot()` do raise the window, because those are the moments a human is meant to look.

### How do I quit Chrome? {#quit}

`PageInterface::close()` closes one tab. `HeadlessChromeInterface::close()` quits the browser, but only one this service launched on its own port. A browser it attached to — a window you opened yourself to sign in — keeps running, and so does the other mode's browser even though both services share one launcher.

## Configuration {#configuration}

### How do I give the two modes different ports? {#two-ports}

Set `port` on `HeadlessChromeInterface` and on `HeadedChromeInterface` separately. See [Configuration](README.md#configuration).

### How do I run Chrome inside a container? {#container}

Set `args` to `['--no-sandbox', '--disable-dev-shm-usage']` on the service you use. A container that blocks Chrome's own sandbox kills the GPU process at startup, and the debugging port never answers. See [Configuration](README.md#configuration).

### Why does the launcher name a different script on Windows? {#start-script}

`Paths::packageStartScript()` returns `bin/start-cdp.sh` on Unix and `bin/start-cdp.ps1` on Windows, and `ensureStartScript()` copies the matching one into the headed profile.

### How do I inspect the options? {#discover-options}

Run `bash bin/console config:options 'Switon\Cdp\HeadlessChromeInterface'` and the headed interface the same way.
