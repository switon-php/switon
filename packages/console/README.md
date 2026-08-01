# Switon Console Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/console/ci.yml?branch=main&label=CI)](https://github.com/switon-php/console/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Reusable terminal primitives for progress bars, terminal width detection, and ANSI styling in Switon Framework.

## Highlights

- **Progress bars:** `ProgressBarInterface` with elapsed and remaining time rendering.
- **Width detection:** terminal width comes from the `COLUMNS` environment variable; no shell probing.
- **ANSI style flags:** `Colors` bitmask constants for foreground, background, and text attributes.
- **Narrow contracts:** swap the progress implementation without changing call sites.

## Installation

```bash
composer require switon/console
```

The package ships primitives only; `switon/cli` wires them for command authoring.

## Quick Start

```php
use Switon\Console\Colors;
use Switon\Console\ProgressBarInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;

final class ImportUsers
{
    #[Autowired] protected ProgressBarInterface $progressBar;
    #[Autowired] protected ConsoleInterface $console;

    public function run(array $rows): void
    {
        $this->progressBar->start(count($rows));

        foreach ($rows as $row) {
            $this->importRow($row);
            $this->progressBar->advance();
        }

        $this->progressBar->finish();
        $this->console->writeLn(
            $this->console->colorize('Import complete', Colors::FC_LIGHT_GREEN | Colors::AT_BOLD)
        );
    }
}
```

Docs: https://github.com/switon-php/console/blob/main/docs/README.md

## License

MIT.
