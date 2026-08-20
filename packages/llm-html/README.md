# Switon LlmHtml Package

[![Build Status](https://github.com/switon-php/llm-html/actions/workflows/build.yml/badge.svg?branch=dev)](https://github.com/switon-php/llm-html/actions)
[![PHP 8.3+](https://img.shields.io/badge/php-8.3%2B-blue.svg)](https://www.php.net/releases/8.3/en.php)

Turn noisy HTML into LLM-ready markdown or plain text: drop chrome, keep the main copy, and leave fetching to the caller.

## Highlights

- **Markdown or plain text** from the same kept region.
- **Main copy first:** prefer an article, `main`, or a trusted content id, and fall back to the body.
- **Non-HTML passthrough:** markdown, JSON, and plain text stay unchanged.
- **No HTTP:** pair it with the fetcher that already has the page.

## Installation

```bash
composer require switon/llm-html
```

## Quick Start

```php
use Switon\Core\Attribute\Autowired;
use Switon\LlmHtml\LlmHtmlInterface;

class PageReader
{
    #[Autowired] protected LlmHtmlInterface $llmHtml;

    public function read(string $html): string
    {
        $page = $this->llmHtml->convert($html);

        return $page->content;
    }
}
```

`convert($html, 'text')` returns the same region as tag-stripped prose. The result's `title`, `cleanup`, and `format` say what was kept.

Docs: https://github.com/switon-php/llm-html/blob/main/docs/README.md

## License

MIT.
