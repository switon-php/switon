# CDP Package {#cdp-package}

`switon/cdp` drives one local Chrome for pages that need a real browser: rendered HTML, site cookies, and HTTP sent from that page. Inject `HeadlessChromeInterface` for silent work. A visible window is `HeadedChromeInterface`, and it keeps a different profile.

The first layer is open a tab, wait for a selector, read HTML, click or fill a field, capture the screen, and send a request that carries the profile cookies. `request()` runs `fetch` inside the page, so a cross-origin URL needs the site's document open first.

**Key features:**

- **Background page:** open a URL with no visible window, wait until an element appears, and read the rendered HTML.
- **Site cookies:** send `GET` and `POST` with the cookies already stored for that profile.
- **Visible debugging:** click an element, fill a field, and capture the current screen.
- **Same tab, more calls:** keep the page open to wait for an element and send follow-up requests from it.
- **Two profiles:** headless `59222`, visible window `59223`, both on `127.0.0.1`.

## Installation {#installation}

**Requirements:** PHP 8.3+, `ext-json`, and a local Chrome or Chromium.

```bash
composer require switon/cdp
```

`HeadlessChromeInterface` binds to `Switon\Cdp\HeadlessChrome`. `HeadedChromeInterface` binds to `Switon\Cdp\HeadedChrome`. `PageInterface` comes from `open()`; do not inject it.

## Quick Start {#quick-start}

Open the document, wait until a selector matches, then read the HTML.

```php
namespace App\Service;

use Switon\Cdp\HeadlessChromeInterface;
use Switon\Core\Attribute\Autowired;

class PageReader
{
    #[Autowired] protected HeadlessChromeInterface $chrome;

    public function html(string $url): string
    {
        $page = $this->chrome->open($url);
        try {
            $page->wait('main');
            return $page->content();
        } finally {
            $page->close();
        }
    }
}
```

`open()` starts Chrome when port `59222` is closed. `PageInterface::close()` closes that tab. `HeadlessChromeInterface::close()` quits the browser.

A document `GET` with no extra headers can skip the page object:

```php
$html = $this->chrome->request('GET', 'https://example.com/docs')['body'];
```

That path returns status `200` and the rendered HTML. It does not call `wait()`. When the page must show an element first, use `open()`, `wait()`, and `content()`.

## Core Concepts {#core-concepts}

### Two Chromes {#two-chromes}

Headless and headed are two services so each can keep its own `port` and profile.

| Service | Port | Profile |
| --- | --- | --- |
| `HeadlessChromeInterface` | `59222` | `~/.switon/chrome/headless` |
| `HeadedChromeInterface` | `59223` | `~/.switon/chrome/headed` |

Both listen on `127.0.0.1`. Cookies written in one profile are invisible to the other. A login in the headed window stays on port `59223`.

To reuse a login from headless, open a visible Chrome on the headless profile and port `59222`. When that port is already open and the window has a page you opened, headless attaches and reads those cookies. A visible window on the headless port that holds only a blank tab is closed and launched again as headless.

`open()` keeps your tab in front, but a later `click()` or `fill()` has to activate the agent's tab: Chrome delivers input to the active tab only, so that tab and yours swap places in the same window. Keep a login window for reuse and let interactive automation own a separate window when both matter.

### Page {#page}

`open($url)` returns one tab as `PageInterface`.

- `wait($selector)` polls until the CSS selector matches, or until `timeout` elapses. Both cases return, so `content()` can still be read. An empty selector throws.
- `open()` and `navigate()` throw when the navigation itself fails, naming Chrome's `net::ERR_…` text. An HTTP status such as `404` is not a navigation failure: it stays readable through `status()` and `content()`.
- `content()` returns the document HTML.
- `click($selector)` clicks the element. `fill($selector, $text, $submit)` clicks the field, replaces its text, and presses Enter when `$submit` is true. `$selector` is a CSS selector or `#<backendNodeId>`; see [Addressing an element](#addressing). The element must be present, have a visible box, and not be covered: the centre point is checked against `document.elementFromPoint()`, and a covering node throws instead of receiving the click.
- These two activate the tab inside its window, which is all `Input.*` needs. They do not raise the application, so an agent loop no longer pulls a visible window in front of whatever the human is typing in. `$submit` sends a real Enter, so a form submits the way it does for a person.
- `screenshot()` returns the PNG bytes of the current viewport and raises the visible tab first — that is the moment to look at it.
- `layout()` reports the box of every node, so a snapshot can order by what a person can see. `viewport()` and `scrollTo()` complete the picture; see [Long pages and scrolling](#long-pages).
- `assert($path, $kind, $expected)` checks one condition and retries it until it holds or time runs out. See [Checking that an action worked](#asserting).
- `fetch($method, $url, $headers, $body)` sends HTTP from this tab, with the profile cookies. A `Referer` header applies to that one call and is cleared afterwards.
- `activate()` brings the tab forward and raises the window. That matters for a visible window.
- `close()` closes the tab. Calling it again is safe.

These actions work on a headless tab and on a visible window. The visible window is the one you watch while debugging.

### Diagnosing a page {#diagnosing}

A snapshot says what is on the page. It does not say why the page is wrong. Three calls close that gap.

`diagnostics()` reports console output, uncaught exceptions, and failed requests — everything a browser console would show you.

```php
$page->click('#save');
foreach ($page->diagnostics() as $entry) {
    // ['type' => 'exception', 'level' => 'error', 'text' => 'ReferenceError: …', 'url' => …, 'line' => …]
}
```

- `type` is `console`, `exception`, `log`, `response`, or `request`.
- `response` carries an HTTP error (`404`, `500`) that `status()` never sees, because `status()` describes the document only.
- The list resets on `navigate()`: lines from the previous document would blame the wrong page.
- Reading drains briefly, so a request that started just before the call still reports.

`evaluate($expression)` runs JavaScript in the tab and returns the value. An exception in the page is raised, so a broken probe says so instead of returning `null`.

`inspect($target, $properties)` reports the computed styles and the box: `display`, `visibility`, `opacity`, `color`, `background-color`, `font-size`, `position`, `z-index`, `overflow`. It answers "why is this invisible, sized wrong, or the wrong colour".

### Checking that an action worked {#asserting}

Clicking is not the same as succeeding. `assert()` checks a real condition on the page and keeps checking for a while, because a page updates after the action:

```php
$page->click('#save');
$result = $page->assert('#status', 'text', 'Saved', 5.0);
// ['ok' => true, 'actual' => 'Saved', 'waitedMs' => 1240, …]
```

`kind` is one of:

| Kind | Passes when |
| --- | --- |
| `visible` | the element has a box and is not `display:none` / `visibility:hidden` / `opacity:0` |
| `hidden` | the opposite of `visible` |
| `text` | its text equals `$expected` |
| `text_contains` | its text contains `$expected` |
| `value` | its `value` equals `$expected` |
| `enabled` | it is not `disabled` |
| `checked` | a checkbox or radio is checked |

A failure is not an exception: `ok` is `false` and `actual` says what the page really shows. An assertion retries the condition itself — not a guessed delay — so a page that takes 1.2s to update passes without the caller knowing the number.

### Addressing an element {#addressing}

`click()`, `fill()`, and `inspect()` accept three forms.

- A CSS selector, as before.
- `#` followed by a `backendNodeId` from `accessibilityTree()`, e.g. `#123`.
- `#<owner>:<session>/<id>` for a node in a cross-origin frame; see [Clicking inside a cross-origin frame](#cross-origin-click).

The node id is the stronger address: it reaches an element inside a shadow root or a same-origin iframe, where no selector from the top document matches. `selector($backendNodeId)` still exists, but it is a label for humans — it can return a selector that matches nothing in those cases.

### Iframes {#iframes}

An iframe is a separate document. Its content is not in the top document's snapshot, so a page that renders in one looks empty.

`frames()` lists child frames. A same-origin frame shares the parent's process, so `frameSnapshot($frame)` reads its accessibility tree. A cross-origin frame runs in its own process: its entry carries a `sessionId`, and `frameEvaluate($sessionId, $expression)` runs script inside it.

```php
foreach ($page->frames() as $frame) {
    $tree = $page->frameSnapshot($frame);
    $title = $page->frameEvaluate($frame['sessionId'], 'document.title');
}
```

### Clicking inside a cross-origin frame {#cross-origin-click}

`click()`, `fill()`, and `inspect()` accept a third address form for a node in another process:

```
#<ownerBackendNodeId>:<sessionId>/<backendNodeId>
```

The owner is the `<iframe>` element in this document; Chrome reports where its content starts, so the frame-local point composes into a point this tab's input accepts. Both pieces come from one `frames()` entry.

```php
$frame = $page->frames()[0];                 // cross-origin entry
$tree = $page->frameSnapshot($frame);
$nodeId = 42;                                // backendDOMNodeId from that tree
$page->click('#' . $frame['ownerBackendNodeId'] . ':' . $frame['sessionId'] . '/' . $nodeId);
```

A same-origin frame needs no prefix: `#42` already composes through the frame offset.

Dialogs are answered for you. An open `alert()` blocks every later command on the tab — the call in flight times out, and so does the next one — so the package accepts the dialog as it opens. `prompt()` is accepted with an empty answer.

### Long pages and scrolling {#long-pages}

A snapshot shows what is on screen. Text below the fold is counted and left out; scroll to read it. A control below the fold stays in the list.

`layout()` reports the box of every node in one call, so a snapshot can put what a person can see first.

```php
$page->layout();      // backendNodeId => {x, y, w, h}, in viewport coordinates
$page->viewport();    // ['width' => 1200, 'height' => 919, 'scrollY' => 0, 'scrollHeight' => 919]
$page->scrollTo('', 800);        // scroll down 800px
$page->scrollTo('#123');         // bring a node into view
```

In a snapshot, the header counts what is below the fold. Text there is left out. A control there carries an `(offscreen)` marker:

```
(120 more below the fold; scroll to bring them into view)
[e1] button "Save"
[e58] (offscreen) button "Archive"
```

Ordering is viewport first, then document order. When a page has more lines than the budget, the tail is dropped and counted, so the visible half survives instead of an arbitrary cut.

**Timing note.** `open()` and `navigate()` return once Chrome reports `networkIdle` — after a framework has fetched what it needs and mounted. Measured on a Vue app, the DOM at that instant already holds the rendered page, so `snapshot()` needs no extra wait. A page that renders on a timer alone (no request follows) settles before it mounts; use `wait($selector)` for that case.

### Requests {#requests}

`HeadlessChromeInterface::request()` opens a tab, performs the call, and closes the tab.

- A plain document `GET` (no headers, no body, no `origin`) opens `$url` and returns its HTML with status `200`.
- Any other call opens `$origin` when you pass it, otherwise `about:blank`, then runs `fetch` in that page. The returned `status` is the HTTP status.

`$origin` is the document URL, so the tab's origin and cookies match the API. `Referer` is a request header, not `$origin`.

```php
$result = $this->chrome->request(
    'POST',
    'https://example.com/api/search',
    ['Referer' => 'https://example.com/search'],
    '{"q":"php"}',
    'https://example.com/',
);
```

`$result` is `array{status: int, body: string}`. A cross-origin `fetch` is blocked by the browser. Call `fetch()` on a `PageInterface` when the tab must stay open across `wait()` and several requests.

## Configuration {#configuration}

Defaults are enough for a local Chrome. Set `timeout` when a page is slow to show the element you wait for.

```yaml
# switon.yml
Switon\Cdp\HeadlessChromeInterface:
  timeout: 30
```

Headed uses its own key, so the two ports stay independent:

```yaml
# switon.yml
Switon\Cdp\HeadedChromeInterface:
  port: 59223
```

| Option | Default | Role |
| --- | --- | --- |
| `port` | `59222` or `59223` | DevTools port for that service |
| `userDataDir` | empty | Empty uses the mode profile under `~/.switon/chrome` |
| `executable` | empty | Empty uses `SWITON_CHROME_PATH`, then `CHROME_PATH`, then a detected install |
| `autoLaunch` | `true` | Launch Chrome when its port is closed |
| `timeout` | `45` | Seconds for navigation, `wait()`, and CDP calls |
| `args` | empty | Extra Chrome flags appended at launch |

`args` is for accommodations the package must not assume. A container that blocks Chrome's own
sandbox needs `--no-sandbox`; a small `/dev/shm` needs `--disable-dev-shm-usage`.

```yaml
# switon.yml
Switon\Cdp\HeadlessChromeInterface:
  args:
    - --no-sandbox
    - --disable-dev-shm-usage
```

Without `--no-sandbox` inside such a container, Chrome exits at startup with
`GPU process isn't usable. Goodbye.` and the port never answers.

### Option Discovery {#option-discovery}

```bash
bash bin/console config:options 'Switon\Cdp\HeadlessChromeInterface'
bash bin/console config:options 'Switon\Cdp\HeadedChromeInterface'
```

## Keep in Mind {#keep-in-mind}

- After `wait()`, read `content()` and confirm the selector's markup is there. A timeout still returns.
- Document `request('GET', $url)` does not wait for a selector.
- Pass `$origin` for an API on a site. The tab must already be that origin for cookies and for the browser's cross-origin rules.
- `autoLaunch: false` attaches only. A closed port raises. On the headed service the message names the launcher in this package — `bin/start-cdp.sh`, or `bin/start-cdp.ps1` on Windows — which starts the headed profile on port `59223`. The headless service has no launcher script: start headless Chrome yourself on its port and profile.
- `close()` quits only a browser this service started on its own port. A window you opened yourself, and the other mode's browser, are left running.

## Next {#next}

- **To debug behavior and defaults:** [FAQ](faq.md#cdp-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
