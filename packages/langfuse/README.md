# Switon Langfuse Package

[![Langfuse CI](https://img.shields.io/github/actions/workflow/status/switon-php/langfuse/ci.yml?branch=main&label=Langfuse%20CI)](https://github.com/switon-php/langfuse/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Langfuse OTLP export for Switon LLM traffic: conversation traces, per-call token usage, and tool spans, driven by the
events the framework already dispatches.

## Highlights

- **No instrumentation calls:** listeners map `CompletionResponded`, `CompletionFailed`, `ToolFinished`, and `TurnSuspended`.
- **Trace identity from the domain:** a turn id becomes the OTLP trace id; sub-agent threads share their host session.
- **Usage that prices correctly:** input, cache, output, and reasoning tokens go out as exclusive buckets.
- **Off by default:** nothing is exported until a Langfuse host is configured.

## Installation

```bash
composer require switon/langfuse
```

## Quick Start

Set the host and keys; the listener registers itself during boot.

```yaml
# switon.yml
Switon\Langfuse\ClientInterface:
  baseUrl: https://cloud.langfuse.com
  publicKey: pk-lf-...
  secretKey: sk-lf-...
```

Docs: https://github.com/switon-php/langfuse/blob/main/docs/README.md

## License

MIT.
