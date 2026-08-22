# Switon CDP Package

[![CDP CI](https://img.shields.io/github/actions/workflow/status/switon-php/cdp/ci.yml?branch=main&label=CDP%20CI)](https://github.com/switon-php/cdp/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Headless Chrome for Switon: open a page, wait for an element, read the rendered HTML, and send cookie-bearing HTTP from that profile.

## Highlights

- **Background page:** open a URL with no visible window, wait until an element appears, and read the rendered HTML.
- **Site cookies:** send `GET` and `POST` with the cookies already stored for that profile. An API on the site starts from that site's document.
- **Visible debugging:** click an element, fill a field, and capture the current screen.
- **Front-end diagnostics:** read console output, uncaught exceptions, and failed requests; inspect computed styles; run JavaScript in the tab.
- **Separate profiles:** headless uses `~/.switon/chrome/headless` on port **59222**. A visible window uses `~/.switon/chrome/headed` on port **59223**. A login stays in the profile that stored it.

## Installation

```bash
composer require switon/cdp
```

## Quick Start

```php
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

Docs: https://github.com/switon-php/cdp/blob/main/docs/README.md

## License

MIT.
