# Switon Beacon Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/beacon/ci.yml?branch=main&label=CI)](https://github.com/switon-php/beacon/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's AI bootstrap for apps: package standards, built-in skills, and JSON discovery commands for code and config.

## Highlights

- **Agent-facing commands:** `tool:*` provides a structured way to inspect the app.
- **Code and config checks:** `class:*`, `config:*`, `di:*`, and `event:*` cover common diagnostic paths.
- **Topic lookup:** `tool:entry` maps common areas like auth, config, controllers, events, routing, and views to the
  right class.
- **Fresh indexes:** class indexes and event scanning keep the lookup map current.
- **Bundled guidance:** standards and skills keep app-facing tooling consistent.

## Installation

```bash
composer require --dev switon/beacon
```

## Quick Start

```bash
bash bin/console tool:list
bash bin/console tool:entry event
bash bin/console config:check switon.yml config
```

Point your agent or `AGENTS.md` at `vendor/switon/beacon/`.

Docs: https://github.com/switon-php/beacon/blob/main/docs/README.md

## License

MIT.
