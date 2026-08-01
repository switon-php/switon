# Switon Command Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/command/ci.yml?branch=main&label=CI)](https://github.com/switon-php/command/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's command metadata layer for auto-discovery, PHPDoc help, AI synopses, and short-option aliases.

## Highlights

- **Automatic discovery:** package commands come from `extra.switon.commands`, and app commands are scanned
  automatically.
- **Docblock help:** command help comes from class and action docblocks.
- **AI synopsis:** `#[Tool]` adds an AI-facing synopsis for an action.
- **Visibility controls:** `#[Hidden]` can hide a command or action from default listings.
- **Explicit option aliases:** `#[ShortOption]` pins short aliases where needed.

## Installation

```bash
composer require switon/command
```

## Quick Start

```php
use Switon\Command\Attribute\ShortOption;
use Switon\Command\Attribute\Tool;

/**
 * Report commands.
 */
final class ReportCommand
{
    /**
     * Return a report summary.
     *
     * @param string $id Report id to inspect
     * @param bool $shout Uppercase the output
     */
    #[Tool('Returns JSON report summary by id.')]
    public function statusAction(#[ShortOption('x')] string $excluded, bool $shout = false): array {
        return ['excluded' => $excluded, 'shout' => $shout];
    }
}
```

`CommandInspector` feeds `cli` help/list output and `beacon` JSON tool metadata.

Rendered from the example above:

```text
report Report commands.
  report:status    Return a report summary.
    Option           Description              Default
    --excluded, -x   Excluded id to inspect   
    --shout, -s      Uppercase the output     false
```

Docs: https://github.com/switon-php/command/blob/main/docs/README.md

## License

MIT.
