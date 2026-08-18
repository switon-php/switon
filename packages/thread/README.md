# Switon Thread Package

[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's thread directory for application services that want the working conversation and an append-only transcript stored apart from the agent loop.

## Highlights

- **One leaf:** settled turns, the open turn, state, and totals each have their own file under the thread directory.
- **Memory stays memory:** a thread with no directory is not written, and sealing it dispatches nothing.
- **The window stays short:** older turns fold into a short head. The transcript keeps the full text.

## Installation

```bash
composer require switon/thread
```

## Quick Start

`@threads` is `@runtime/threads`. Point the thread's directory at a leaf under that root, then create it. A missing directory is left in memory.

```php
$threads->create($thread);
$threads->saveTurn($thread);
$threads->commit($thread);
```

`commit` appends the sealed turn and removes the open-turn file. Load the same directory later with `load`.

Docs: https://github.com/switon-php/thread/blob/main/docs/README.md

## License

MIT.
