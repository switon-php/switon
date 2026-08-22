# Design Rationale {#design-rationale}
`switon/search` keeps agent lookup on four tools and one site registry.

## See also {#see-also}

- [Search Package](README.md) — usage, site files, and the public tools
- [FAQ](faq.md#search-faq) — common questions and troubleshooting

---

## Core Problem {#core-problem}

Agents look things up in two ways, and each way drifts on its own.

- One profile hard-codes a site client.
- Another scrapes a search page and treats a challenge page as a result.
- A desk adds a site by shipping code instead of a file.
- The same public page comes back under a different URL from each engine.

The framework needs one lookup boundary that stays the same for open web and for a curated site.

## Primary Goal {#primary-goal}

`switon/search` standardizes the four tools first, then describes a curated site as data.

- **Benefit:** a profile grants ids, not clients.
- **Benefit:** a new site is a YAML file in the registry.
- **Cost:** open-web engines stay code. A site file does not replace Baidu, Google, Bing, or Zhihu.

## Core Choice {#core-choice}

Open web and curated sites stay separate wires.

- **`web_search`** runs the four engines in order and dedupes normalized URLs.
- **`web_fetch`** turns one URL into readable text.
- **`site_search`** loads one `Switon\Search\Site` and asks `Switon\Search\SiteExtractorInterface` to map the response.
- **`site_list`** reads the same `Switon\Search\SitesInterface` registry the `site` enum uses.

A failed open-web engine is omitted, and `web_search` returns no hits when every engine fails. `site_search` throws on an empty query, an unknown id, or a transport failure. A non-200 site response is an empty result with `status`, not a throw.

## Trade-Off {#trade-off}

The package does not collapse the same page across engines, and it does not post-filter hosts that ignored `site:`.

- **Benefit:** each engine's URL is returned as that engine published it.
- **Benefit:** a site file can keep rows the open-web engine drops, as `zhihu` does for people.
- **Cost:** dedupe will not merge a Baidu redirect with the Google URL for the same article.

## Deliberate Simplicity {#deliberate-simplicity}

Curated search is one wire, not one tool per site.

- **The registry** is the list of ids.
- **The site file** is the request and the mapping.
- **The extractor** applies that mapping to one document.
- **Pagination** is `size` on the file, checked when `page` is greater than 1.

That keeps a desk on a YAML file and leaves engine-specific HTML in the four open-web engines.
