# LLM Package {#llm-package}

`switon/llm` is a named catalog of chat models. Pick one by name, alias, or by leaving the name empty for the first model. The agent loop and thread storage stay outside this package.

**Key features:**

- **Named models:** each entry in `~/.switon/llm.yml` is one model you can call.
- **Aliases:** a short name points at one model. The first declaration wins.
- **Default model:** an empty name uses the first model in the catalog.
- **Two protocols:** OpenAI-compatible chat by default. A connection can ask for Anthropic Messages instead.
- **Commands:** list the catalog, show one model, or send a one-shot reply.

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/llm
```

On boot the catalog loads from `~/.switon/llm.yml`. The network call waits until a completion or a stream.

## Quick Start {#quick-start}

Put one model in the catalog:

```yaml
openai:
  apiKey:
  baseUrl: 'https://api.openai.com/v1'
  models:
    default:
      model: 'gpt-4o-mini'
      alias: [main, fast]
```

Then ask for a reply:

```bash
bash bin/console llm:ping main
```

An empty name pings the first model. `llm:list` prints the catalog. `llm:show` prints one model. The API key is not printed.

## Configuration {#configuration}

One connection, one model, is enough. Add another connection when a second host should answer under its own name.

A model key becomes the route `{connection}/{modelKey}`. In the example that is `openai/default`. `alias` adds shorter names for the same model.

Omitted `window` is `128k`. Omitted `image` is on. Omitted `video` is off. The `k` suffix is lowercase.

An `apiKey` line is stored, even when the value is empty. Leave the line out to keep the client default.

To skip the file and declare the catalog in the app instead, set `file` to null on `Switon\Llm\LlmDiscoveryInterface`.

### Option Discovery {#option-discovery}

```bash
bash bin/console config:options 'Switon\Llm\LlmDiscoveryInterface'
```

## Keep in Mind {#keep-in-mind}

- No models means the catalog file is missing, or `file` is null and no providers are bound.
- An unknown name is rejected. It does not fall back to the first model.
- `main` works only when the catalog declares that alias.

## Next {#next}

- **To debug lookup and setup:** [FAQ](faq.md#llm-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
