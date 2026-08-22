# Switon Search Package

[![Search CI](https://img.shields.io/github/actions/workflow/status/switon-php/search/ci.yml?branch=main&label=Search%20CI)](https://github.com/switon-php/search/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Open-web lookup and curated per-site search for agents. Four tools: `web_search`, `web_fetch`, `site_search`, and `site_list`.

## Highlights

- **Open web:** `web_search` queries Baidu, Google, Bing, and Zhihu, then dedupes.
- **Page fetch:** `web_fetch` returns readable text, or the raw body.
- **Curated sites:** one `site_search` wire over a registry of site files.
- **Catalog:** `site_list` returns id, label, and whether a site paginates.

## Installation

```bash
composer require switon/search
```

## Quick Start

The package registers the four tools. An agent profile lists the ids it may call:

```yaml
tools:
  - web_search
  - web_fetch
  - site_search
  - site_list
```

Docs: https://github.com/switon-php/search/blob/main/docs/README.md

## License

MIT.
