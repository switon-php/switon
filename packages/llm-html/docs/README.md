# LLM HTML Package {#llm-html-package}

`switon/llm-html` turns a noisy HTML document into markdown or plain text for an LLM. Choose it when a fetcher already has the page and you want the article, not the chrome. It does not download URLs and it does not truncate the result.

**Key features:**

- **One conversion call:** inject `Switon\LlmHtml\LlmHtmlInterface` and pass the document.
- **Two formats:** `markdown` (default) and `text` render the same kept region.
- **Main copy first:** an `article`, `main`, `role="main"`, or a content id wins over the body.
- **Passthrough:** a payload that is not an HTML document is returned unchanged.
- **No network:** relative links stay as written. The caller fetches and truncates.

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/llm-html
```

`Switon\LlmHtml\LlmHtmlInterface` is bound to `Switon\LlmHtml\LlmHtml`.

## Quick Start {#quick-start}

Inject `Switon\LlmHtml\LlmHtmlInterface` and convert the document you already have.

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\LlmHtml\LlmHtmlInterface;

class PageReader
{
    #[Autowired] protected LlmHtmlInterface $llmHtml;

    public function read(string $html): string
    {
        $page = $this->llmHtml->convert($html);

        return $page->title . "\n\n" . $page->content;
    }
}
```

Pass `'text'` when the caller wants prose without markdown markers:

```php
$text = $this->llmHtml->convert($html, 'text');
```

Any format other than `text` is treated as `markdown`.

## Core Concepts {#core-concepts}

### Result {#result}

`convert()` returns `Switon\LlmHtml\LlmHtmlResult`.

- `content` is the kept region, rendered as markdown or plain text.
- `title` comes from `<title>`, then `og:title`.
- `description` comes from the description meta, then `og:description`.
- `format` is `markdown` or `text`.
- `strategy` names the root that was kept: `article`, `main`, `role:main`, `id:{id}`, `dense`, `body`, or `passthrough`.
- `usedMainContent` is true when that root is a real content area rather than the whole body.
- `cleanup` is `original` (the input string), `cleaned` (hard noise removed), or `pruned` (structural chrome removed as well).

### What counts as HTML {#html}

A `<!doctype html>`, `<html>`, `<head>`, `<body>`, or `<script>` marks the payload as HTML when it leads the text. A UTF-8 BOM, whitespace, or a leading comment may come first. A tag mentioned later in markdown or prose does not. Other text skips the DOM and comes back with `strategy=passthrough` and `cleanup=original`.

### Cleanup and the kept region {#cleanup}

The first pass removes scripts, styles, and hard chrome, then picks a content root. A trusted root that is long enough stops there (`cleanup=cleaned`). Otherwise a second pass removes structural landmarks such as nav, aside, and outer header or footer. If that pass collapses the text, the cleaned pass is kept. When it holds, `cleanup` is `pruned`.

The markdown renderer keeps headings, lists, tables, and links. Fragment-only, `javascript:`, `data:`, and `mailto:` links are dropped. Other hrefs, including root-relative ones, are copied as written.

## Configuration {#configuration}

The early-stop and collapse thresholds are config on `Switon\LlmHtml\LlmHtmlInterface`. Leave them at the defaults for ordinary articles. Lower `earlyStopMax` when long trusted pages should still take the stricter pass.

Configure this package in `switon.yml`:

```yaml
# switon.yml
Switon\LlmHtml\LlmHtmlInterface:
  earlyStopMin: 800
  earlyStopMax: 10000
```

### Option Discovery {#option-discovery}

Run:

```bash
bash bin/console config:options 'Switon\LlmHtml\LlmHtmlInterface'
```

## Keep in Mind {#keep-in-mind}

- This package does not fetch. Give it the body your HTTP client already downloaded.
- Truncation is the caller's job. A long article is returned in full.
- `strategy=body` means no stronger root won. The text can still include leftover page chrome.

## Next {#next}

- **To debug a surprising result:** [FAQ](faq.md#llm-html-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
