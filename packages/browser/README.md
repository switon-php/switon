# Switon Browser Package

[![Browser CI](https://img.shields.io/github/actions/workflow/status/switon-php/browser/ci.yml?branch=main&label=Browser%20CI)](https://github.com/switon-php/browser/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Headed agent tabs for Switon: a page the agent names, an accessibility snapshot with `eN` refs, then click and type on that page.

## Highlights

- **Tabs the agent names:** every browser tool takes a `tab` id the agent chooses. The same id reuses that page and its `eN` refs; a new id opens another tab. Agents do not share tabs. Chrome is still one process. `browser_close` closes one id; the rest close when the agent is collected.
- **Snapshot refs:** read the page as text. Interactive rows are `eN` ids, addressing the node directly so shadow DOM and iframes are reachable. Text on screen is included. Text below the fold is counted in the header and left out; a control there stays as one `(offscreen)` line. Prose past a fixed character budget folds into one count line, so a long article costs about what its opening screen does.
- **Act on the page:** click and type those refs. A screenshot saves the current PNG.
- **Explain what broke:** `browser_console` returns console output, uncaught exceptions, and failed requests; `browser_inspect` returns computed styles and the box of an element.
- **Know that it worked:** `browser_assert` checks a ref — or a CSS selector, for text the page shows back without a ref — against a condition, and retries, so a page that updates late still passes.

Silent page reads and cookie-bearing HTTP live on `switon/cdp`.

## Tools

| Tool | Role |
| --- | --- |
| `browser_navigate` | open a URL on a named tab and return the snapshot |
| `browser_close` | close one named tab, return the id that was closed |
| `browser_snapshot` | re-read the page as text with `eN` refs |
| `browser_click` | click a ref, return the new snapshot |
| `browser_type` | replace a ref's text, optionally submit |
| `browser_take_screenshot` | save a PNG of the tab |
| `browser_console` | console lines, exceptions, and 4xx/5xx responses |
| `browser_inspect` | computed CSS and the box for a ref |
| `browser_scroll` | reach elements marked `(offscreen)`, or scroll to look lower |
| `browser_assert` | check that a ref is visible, says text, has a value, is enabled or checked |

`browser_console` is the one to reach for when a page did not behave as expected: an uncaught exception and a failed request never appear in a snapshot.

## Installation

```bash
composer require switon/browser
```

## Quick Start

```php
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

Docs: https://github.com/switon-php/browser/blob/main/docs/README.md

## License

MIT.
