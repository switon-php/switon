# Switon Tooling Package

[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's tool catalog for application services that want named file, search, and shell tools without owning the agent loop.

## Highlights

- **Built-in tools:** read, write, edit, list, search, shell, lint, media, recall, and a short sleep are available by name.
- **Added tools:** `extra.switon.tools` adds a name. A name already taken stays.
- **Replace a built-in:** application config can replace a name or drop it.
- **Large output:** a long result is stored beside the thread and can be read back later.
- **The loop stays outside:** this package runs one tool call. It does not decide the next step.

## Installation

```bash
composer require switon/tooling
```

## Quick Start

The built-in names are already registered. A package adds a new name under `extra.switon.tools`. Repeating `read` there does not replace it.

```json
{
    "extra": {
        "switon": {
            "tools": {
                "mine": "App\\Tool\\MineTool"
            }
        }
    }
}
```

Docs: https://github.com/switon-php/tooling/blob/main/docs/README.md

## License

MIT.
