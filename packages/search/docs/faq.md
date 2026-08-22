# Search FAQ {#search-faq}
Short answers for tool calls, site files, and empty results.

> **Prerequisites:** [Search Package](README.md)

## Getting Started {#getting-started}

### How does an agent call search? {#call-search}

The package registers `web_search`, `web_fetch`, `site_search`, and `site_list`. Put the ids a profile may call under `tools:`. See [Quick Start](README.md#quick-start).

### Where do site ids come from? {#site-ids}

`site_list` returns the registry: id, label, and whether the site paginates. Built-in files ship with the package. An app file in `@resources/sites` or `~/.switon/sites` replaces the same id. A desk file is registered as `{deskId}.{id}`. See [Registry](README.md#registry).

### Do I need a browser? {#browser}

Open-web search, `web_fetch`, and any site with `fetch: browser` or a `ready` selector need `Switon\Core\WebFetcherInterface`. `switon/cdp` provides it. A site that stays on curl does not.

## Configuration {#configuration}

### How do I add a site? {#add-site}

Add one YAML map under `@resources/sites` or `~/.switon/sites`. The file stem is the id when `id` is omitted. See [Site file](README.md#site-file).

### How do I inspect options? {#discover-options}

Run `bash bin/console config:options 'Switon\Search\WebSearchInterface'`. Use `Switon\Search\SiteSearchInterface` or `Switon\Search\WebFetchInterface` for the other services.

### When does `page` work? {#page}

`site_search` accepts `page` greater than 1 only when that site's file sets `size`. Otherwise the call fails.

## Behavior {#behavior}

### What comes back when every engine fails? {#all-engines-fail}

`web_search` returns an empty list and a warning. One failed engine is skipped; the others still contribute.

### Does `site` limit every engine? {#site-filter}

No. `site` is a hostname. It is added as `site:` only for engines that accept that filter. Zhihu ignores it. See [Tools](README.md#tools).

### Why do Zhihu results differ between the two tools? {#zhihu}

`web_search` keeps Zhihu questions, answers, and articles. `site_search` on `zhihu` uses the site file, which also keeps people rows.

### What URL can `web_fetch` open? {#fetch-url}

An absolute `http` or `https` URL with a host. Set `raw` to keep the body instead of readable text.

## Troubleshooting {#troubleshooting}

### Why is `web_search` empty? {#empty-web-search}

Check that `Switon\Core\WebFetcherInterface` is bound, then the query. A challenge page or a non-200 response from an engine is skipped, not returned as a hit.

### Why does `site_search` reject `page`? {#page-rejected}

That site file has no `size`. Page 1 still runs. Set `size` when the request template understands `{page}`, `{offset}`, or `{limit}`.

### Why does a browser site fail? {#browser-site}

`fetch: browser` or a `ready` selector needs `Switon\Core\WebFetcherInterface`. When `fetch: browser` and `referer` are both set, the fetch does not wait on `ready`.
