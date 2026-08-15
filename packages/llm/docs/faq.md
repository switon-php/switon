# LLM FAQ {#llm-faq}

Short answers for setup and lookup.

> **Prerequisites:** [LLM Package](README.md)

## Getting Started {#getting-started}

### How do I send one reply? {#ping}

`bash bin/console llm:ping`, with a name or with no name. No name uses the first model. See [Quick Start](README.md#quick-start).

### Where is the catalog? {#catalog-file}

`~/.switon/llm.yml`. Set `file: null` on `Switon\Llm\LlmDiscoveryInterface` to use the app config instead.

## Lookup {#lookup}

### Which names work? {#names}

The route, such as `openai/default`, an alias such as `fast`, or an empty name for the first model. Anything else is rejected.

### Why did a later alias vanish? {#first-wins}

The first declaration of a name wins. A later model that repeats it is ignored.

### Does list print the API key? {#secrets}

No.
