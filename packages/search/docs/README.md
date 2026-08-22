# Search Package {#search-package}
`switon/search` gives agents one open-web lookup and one curated site registry. Choose it when a profile should search the public web, fetch a page, or query a known site through a file instead of a new client. The package does not rank results or merge the same page across engines that publish different URLs.

**Key features:**

- **Open web:** `web_search` runs Baidu, Google, Bing, and Zhihu one after another, then dedupes.
- **Page fetch:** `web_fetch` downloads a URL and returns readable text, or the raw body.
- **One site wire:** `site_search` takes a registry id. `site_list` is the catalog of those ids.
- **Site files:** a YAML file is a site. Later directories replace the same id. A desk mounts its own files beside the built-ins.

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/search
```

Open-web search, `web_fetch`, and any site that uses the browser go through `Switon\Core\WebFetcherInterface`. `switon/cdp` provides that implementation. A site that stays on curl does not need it.

The package registers `web_search`, `web_fetch`, `site_search`, and `site_list` through `extra.switon.tools`.

## Quick Start {#quick-start}

List the tool ids on the agent profile that may call them:

```yaml
tools:
  - web_search
  - web_fetch
  - site_search
  - site_list
```

`web_search` takes `query`, and optionally `site` (a hostname) and `lang`. `web_fetch` takes an absolute `url`, and optionally `raw`. `site_search` takes `site` (a registry id) and `query`, and optionally `page`. `site_list` takes no arguments.

## Core Concepts {#core-concepts}

Order follows a call: **which tool**, then **where a site id comes from**, then **how a site file reads a response**.

### Tools {#tools}

`web_search` asks each engine in turn. A failed engine is skipped. When every engine fails, the tool returns an empty list and a warning.

`site` is a hostname such as `github.com`. It is appended as `site:` only on engines that accept that filter. Zhihu does not. Bing defaults to `zh-CN` when `lang` is omitted.

`web_fetch` accepts an absolute `http` or `https` URL. `raw` skips the readable-text conversion.

`site_search` runs one registered site. `page` greater than 1 is accepted only when that site sets `size`. There is no `type` argument.

### Registry {#registry}

`Switon\Search\SitesInterface` is the registry. `Sites::reload()` reads `*.yml` from these directories, later files winning on the same id:

| Order | Directory |
|-------|-----------|
| 1 | `@switon/search/resources/sites` |
| 2 | `@resources/sites` |
| 3 | `~/.switon/sites` |

`reload()` keeps rows already registered. `site_list` is the catalog: id, label, and whether `size` allows another page.

### Site file {#site-file}

One YAML map. The file stem is the id when `id` is omitted. A `response` mapping cleans the document into records. An empty `response` does not. A record's fields are whatever `item` declares.

```yaml
id: github
description: GitHub repository search.
request: https://api.github.com/search/repositories?q={keyword}&per_page=10
response:
  kind: json
  each: items
  item:
    title:
      - full_name
      - name
    url: html_url
    snippet: description
```

| Key | Value |
|-----|--------|
| `id` | Registry id. Omitted means the file stem |
| `description` | Label `site_list` shows |
| `request` | URL template. `{keyword}` is the query |
| `ready` | Optional CSS selector. A non-empty value uses the browser and waits for a match |
| `fetch` | Optional `curl` or `browser`. Absent means curl |
| `referer` | Optional Referer template. May contain `{keyword}` |
| `size` | Optional. Set it so `page` greater than 1 is accepted |
| `jsonp` | Optional callback name when the body is `cb({...});` |
| `response` | How to read the document. Empty means the mapping does not clean it |
| `response.kind` | `html` or `json`. Anything other than `json` is read as HTML |
| `response.each` | Path to the list |
| `response.item` | Optional. Field name to a path, a template, a list of alternatives, or a `by` table. Absent means the row is passed through |

`{keyword}` in `request` and `referer` is percent-encoded. `{offset}`, `{limit}`, and `{page}` are decimal.

A non-empty `ready` uses the browser. `fetch: browser` uses the browser too. When `fetch: browser` and `referer` are both set, the request is a same-origin path with that Referer and does not wait on `ready`.

A field value is one alternative, or a list tried from top to bottom. The first hit is the value. If every alternative misses, the field is left out. A line misses on a missing value, null, `false`, `""`, `0`, or an array. `"0"` hits.

`each` for `html` is a CSS selector; a field path is a selector against that item. A final `@name` reads the attribute, otherwise the text. `each` for `json` is a dot path to an array. `.` means the response itself is that array. A field path is a dot path from that item.

An alternative that contains `{` and `}` is a template. Each `{path}` is read with the rules for `kind`. If any placeholder misses, the template misses. A `url` placeholder is percent-encoded per path segment (`owner/repo` keeps its slashes). Other fields copy the value as-is. A value that is one whole placeholder (`"{object.url}"`) is that value, not a template.

After a hit, a `url` is trimmed. Only `http` and `https` stay; `javascript:`, `#…`, and any other scheme miss. A relative URL is resolved against the response URL. Any other field is entity-decoded, tag-stripped, trimmed, and collapsed. A miss tries the next alternative.

A mapped row with no `title` or `url` is dropped. Order stays the response order. Values are not truncated. Leave `item` out and the row is passed through, nested values included.

A field whose value is a table with `by` picks a template from that table. `by` names a path; its value selects the matching key. `_` is the fallback.

```yaml
url:
  by: object.type
  article: https://zhuanlan.zhihu.com/p/{object.id}
  answer: https://www.zhihu.com/question/{object.question.id}/answer/{object.id}
  people: https://www.zhihu.com/people/{object.url_token}
  _: "{object.url}"
```

A `url` field may instead be a table with `value` (a path, a template, or a list of alternatives), `https: true` (upgrade `http://` to `https://`), and `base` (resolve a relative link against this URL instead of the fetched document).

## Configuration {#configuration}

The four tools are registered by the package. A class string under `tools` replaces that name. `null` drops it.

```yaml
# switon.yml
Switon\Tooling\ToolsInterface:
  tools:
    web_search: null
```

### Option Discovery {#option-discovery}

Run:

```bash
bash bin/console config:options 'Switon\Search\WebSearchInterface'
```

Query `Switon\Search\SiteSearchInterface` or `Switon\Search\WebFetchInterface` the same way for the other two services.

## Keep in Mind {#keep-in-mind}

- Open-web calls need a `Switon\Core\WebFetcherInterface` implementation. `switon/cdp` supplies one.
- `page` greater than 1 fails when the site file leaves `size` unset.
- `web_search` on Zhihu keeps questions, answers, and articles. `site_search` on the `zhihu` file also keeps people rows.

## Next {#next}

- **To debug a call or a site file:** [FAQ](faq.md#search-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
