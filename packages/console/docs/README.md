# Console Package {#console-package}
`switon/console` provides reusable terminal primitives for Switon apps: progress bars, terminal width detection, and ANSI color flags. Choose it when commands or workers need a shared progress and styling layer instead of reinventing it per app.

**Key features:**

- **Progress bars:** `Switon\Console\ProgressBarInterface` (default impl `Switon\Console\ProgressBar`) auto-starts, clamps progress to the configured max, and renders elapsed and remaining time.
- **Width detection:** terminal width comes from the `COLUMNS` environment variable, read at each consuming layer; no shell probing.
- **ANSI style flags:** `Switon\Console\Colors` provides bitmask constants for foreground, background, and text attributes.
- **Small contract:** `ProgressBarInterface` stays narrow so apps can swap implementations without touching call sites.

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/console
```

The package ships primitives only; it does not register application bindings. The `switon/cli` package wires them for command authoring.

## Quick Start {#quick-start}

Inject `ProgressBarInterface` for progress, and combine `Colors` with a `Switon\Core\ConsoleInterface` implementation for styled output.

```php
namespace App\Service;

use Switon\Console\Colors;
use Switon\Console\ProgressBarInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;

class ImportUsers
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

## Core Concepts {#core-concepts}

### Progress bars {#progress-bars}

`ProgressBar` is a stateful in-place output primitive for one terminal task.

- `start(?int $max = null)` begins rendering; `max` defaults to `1` when omitted.
- `advance($step = 1)`, `setProgress($current)`, and `finish()` auto-call `start()` when the bar has not started yet.
- `setProgress()` clamps to the current max; `finish()` writes the last frame and a trailing newline.
- `setFormat()` accepts placeholders `%current%`, `%max%`, `%percent%`, `%bar%`, `%elapsed%`, `%remaining%`, and `%rate%`.
- `setMessage()` appends a short note to the rendered line.

### Terminal width {#terminal-width}

Width is taken from the `COLUMNS` environment variable, which interactive shells export. Each rendering layer reads it once and caches the result; there is no shell probing and no shared detector service.

- Unset or non-numeric `COLUMNS` falls back to `120` columns.
- Values below `20` are treated as unusable and fall back to the default.
- Set `COLUMNS` in the environment (or call `putenv('COLUMNS=120')`) to pin the width when running non-interactively.

### ANSI colors {#ansi-colors}

`Colors` groups ANSI foreground, background, and attribute flags as integer bitmasks for `Switon\Core\ConsoleInterface::colorize()`. Combine them with `|`. The package supplies the constants only; rendering belongs to a `ConsoleInterface` implementation such as `Switon\Cli\Console`.

## Keep in Mind {#keep-in-mind}

- `ProgressBar` is meant for one live terminal flow. Sharing one instance across concurrent tasks produces interleaved output.
- Width is read from `COLUMNS`, so a resized terminal keeps its startup width unless the process re-reads the variable.
- `Colors` are bitmasks, not named themes; build presets in your app if you need named styles.

## Next {#next}

- **To print, prompt, and colorize from commands:** [CLI Package](../../cli/docs/README.md)
- **For common questions:** [FAQ](faq.md#console-faq)
- **For trade-offs and rationale:** [Design Rationale](design.md#design-rationale)
