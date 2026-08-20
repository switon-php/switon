# LLM HTML FAQ {#llm-html-faq}

Short answers for conversion results and boundaries.

> **Prerequisites:** [LLM HTML Package](README.md)

## Getting Started {#getting-started}

### How do I convert a page? {#convert}

Inject `Switon\LlmHtml\LlmHtmlInterface` and call `convert()` with the HTML string. See [Quick Start](README.md#quick-start).

### Does this package download the URL? {#no-http}

No. Pass the document you already have. `Switon\Search\WebFetch` is one caller that does the download and then converts HTML here.

### How do I get plain text instead of markdown? {#plain-text}

Call `convert($html, 'text')`. Both formats use the same kept region. Any other format string is markdown.

## Results {#results}

### Why did the original markdown or JSON come back unchanged? {#passthrough}

The payload did not look like an HTML document. Passthrough sets `strategy` to `passthrough` and `cleanup` to `original`. A string that merely contains a `<div>` is not treated as HTML.

### Why is `strategy` `body`? {#body-strategy}

No `article`, `main`, `role="main"`, or content id scored as the page copy, so the body was kept. Expect more chrome in `content`.

### Why are some links missing? {#links}

Fragment-only, `javascript:`, `data:`, and `mailto:` links are dropped. Every other href is copied as written, including root-relative paths. This package does not resolve them against the page URL.

### Who shortens a long article? {#truncation}

The caller. `convert()` returns the kept region in full.
