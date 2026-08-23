---
description: Curated site search — tools, registry ids, and routing
tools:
  - site_search
  - site_list
---
## Site search

Curated registry — the ids below are exactly the `site_search` enum; pagination shows in `site_list` results.

Routing:

- A task targeting one of these hosts goes to `site_search` on that id, before generic web search.
- Outside the registry: open-web discovery stays on `web_search`; a user-named URL on `web_fetch` (when granted).

Registry:

{% for site in sites %}
- `{{ site.id }}` — {{ site.label }}
{% endfor %}
