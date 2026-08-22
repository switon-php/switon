# Browser Package {#browser-package}

`switon/browser` is headed Chrome for an agent: the agent names each tab, snapshots that page as text with `eN` refs, then clicks and types those refs. Silent HTML and cookie-bearing HTTP stay on `switon/cdp`.

**Key features:**

- **Tabs the agent names:** every tool takes a `tab` id. The same id reuses that page and its refs; a new id opens another tab. Two agents do not share ids. `HeadedBrowserInterface` is still one tab, for a PHP caller that is not an agent.
- **Snapshot refs:** interactive rows are `eN` ids on `backendNodeId`, so shadow DOM and iframes are reachable. Text on screen is included. Text below the fold is counted in the header and left out; a control there stays as one `(offscreen)` line.
- **Act, then re-read:** click, type, and scroll return a fresh snapshot. Older refs are stale.
- **Explain a miss:** `browser_console` reports console output, uncaught exceptions, and failed requests. `browser_inspect` reports computed style. `browser_assert` retries a condition.

## Installation {#installation}

**Requirements:** PHP 8.3+, a local Chrome or Chromium, and `switon/cdp`.

```bash
composer require switon/browser
```

`HeadedBrowsersInterface` binds to `Switon\Browser\HeadedBrowsers`. `open($agent, $tab)` creates or reuses that name. `tab($agent, $tab)` reuses it and fails when the name is new. `close($agent, $tab)` closes one name and returns the id it closed. When the agent is collected, its remaining tabs close. Tools register from this package's `composer.json`.

## Quick Start {#quick-start}

```php
namespace App\Service;

use Switon\Browser\HeadedBrowserInterface;
use Switon\Core\Attribute\Autowired;

class AgentTab
{
    #[Autowired] protected HeadedBrowserInterface $browser;

    public function open(string $url): string
    {
        $this->browser->navigate($url);

        return $this->browser->snapshot();
    }
}
```

`navigate()` starts headed Chrome on port `59223` when that port is closed. `closePage()` closes the tab.

An agent with several pages injects `HeadedBrowsersInterface` instead and names each page:

```php
$tabs->open($agent, 'docs')->navigate($url);      // creates the id on first use
$tabs->tab($agent, 'docs')->snapshot();          // later calls reuse it
$tabs->close($agent, 'docs');                    // releases one id
```

Ids are scoped to the agent object. Two agents may both use `docs` and get different pages.

## Core Concepts {#core-concepts}

### Snapshot refs {#snapshot-refs}

A snapshot is URL, title, and lines. Interactive lines look like `[e12] button "Save"`. Pass `e12` to `click()`, `type()`, `inspect()`, `assert()`, or `scroll()`. The id is a `backendNodeId`, not a CSS selector.

Click and type drop the current refs. Snapshot again before the next action.

### Below the fold {#below-the-fold}

A long page does not dump every paragraph. Visible text is in the body. Offscreen text is a count in the header: `(N more below the fold; scroll to bring them into view)`. An offscreen control stays as one `(offscreen)` line so it can still be clicked. `scroll()` centres a ref or moves by pixels.

Prose also has its own budget. Past roughly one screen of page text, further paragraphs collapse into one `(N more text lines; ...)` line, so a forty-paragraph page costs about what its first dozen do. Controls are never folded: every one keeps its `eN` and stays clickable.

Set that budget per host with `$maxTextChars` on `HeadedBrowser` (default `SnapshotBuilder::MAX_TEXT_CHARS`, 4000). It is a host decision, not a tool argument: an agent cannot know a page's useful length in advance, and a budget that changes between calls would make two snapshots incomparable.

### Tools {#tools}

Every tool takes a required `tab` id. The same id keeps one page and its `eN` refs; a ref from one id is meaningless on another.

| Tool | Role |
| --- | --- |
| `browser_navigate` | open a URL on a named tab and return the snapshot |
| `browser_close` | close one named tab |
| `browser_snapshot` | re-read the page |
| `browser_click` | click a ref, return the new snapshot |
| `browser_type` | replace a ref's text |
| `browser_scroll` | centre a ref, or scroll by pixels |
| `browser_take_screenshot` | save a PNG |
| `browser_console` | console, exceptions, 4xx/5xx |
| `browser_inspect` | computed CSS and box |
| `browser_assert` | retry a condition on a ref |

There is no `browser_evaluate` tool. `HeadedBrowserInterface::evaluate()` exists for PHP callers. Dialogs are accepted by `switon/cdp`.

### Option Discovery {#option-discovery}

```bash
bash bin/console config:options 'Switon\Browser\HeadedBrowsersInterface'
bash bin/console config:options 'Switon\Browser\HeadedBrowserInterface'
bash bin/console tool:list
```

## Keep in Mind {#keep-in-mind}

- A snapshot is not a screenshot. Colour and overlap need `browser_take_screenshot`. Failed requests need `browser_console`.
- `wait()` on CDP still returns when the selector never matches. After `browser_navigate`, read the snapshot.
- Headed Chrome is port `59223` and `~/.switon/chrome/headed`. Headless fetch is a different profile.

## Next {#next}

- **To debug refs and timeouts:** [FAQ](faq.md#browser-faq)
- **To understand the split with CDP:** [Design Rationale](design.md#design-rationale)
- **CDP page driver:** https://github.com/switon-php/cdp/blob/main/docs/README.md
