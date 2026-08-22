# Switon Ai Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/ai/ci.yml?branch=main&label=CI)](https://github.com/switon-php/ai/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Stable AI wire contracts for Switon — shared primitives above `core`, below providers (`llm`), tool registry (`tooling`), catalog (`agents`), skill catalog (`skill`), `thread`, MCP client, and `agency`.

## Highlights

- **Wire contracts:** messages, tool calls, streaming fragments, and token counts.
- **Boundary:** providers, the tool registry, catalogs, thread storage, and the agent loop stay outside.
- **Inbox:** one piece of mail, the model id, and the chosen effort.
- **Suspend:** a turn can wait for a tool reply, a host review, or a retry, then continue.
- **Drop a turn:** remove it from working memory.

## Installation

```bash
composer require switon/ai
```

## Quick Start

```php
use Switon\Ai\AgentInterface;
use Switon\Ai\Envelope;

/** @var AgentInterface $agent */
$agent->deliver(Envelope::of('hi', 'main'));
$turn = $agent->run(Envelope::of('hi', 'main'));
```

Docs: https://github.com/switon-php/ai/blob/main/docs/README.md

## License

MIT.
