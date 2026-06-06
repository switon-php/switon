# Console Package {#console-package}
`switon/console` provides reusable terminal primitives for Switon apps: progress bars, terminal width detection, and ANSI color flags. Choose it when commands or workers need a shared progress and styling layer instead of reinventing it per app.

**Key features:**

- **Progress bars:** `Switon\Console\ProgressBarInterface` (default impl `Switon\Console\ProgressBar`) auto-starts, clamps progress to the configured max, and renders elapsed and remaining time.
- **Width detection:** `Switon\Console\TerminalWidthDetectorInterface` caches the current terminal width and falls back through Unix and Windows discovery commands.
- **ANSI style flags:** `Switon\Console\Colors` provides bitmask constants for foreground, background, and text attributes.
- **Small contracts:** progress and width interfaces stay narrow so apps can swap implementations without touching call sites.

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

`TerminalWidthDetector` returns a cached width value and refreshes only after `clearCache()`.

- Reads `$_ENV['COLUMNS']` first.
- On Unix, falls back to `stty size` and then `tput cols`.
- On Windows, falls back to `mode con` and then PowerShell.
- Values below the supported minimum (`20`) are raised to that minimum.

### ANSI colors {#ansi-colors}

`Colors` groups ANSI foreground, background, and attribute flags as integer bitmasks for `Switon\Core\ConsoleInterface::colorize()`. Combine them with `|`. The package supplies the constants only; rendering belongs to a `ConsoleInterface` implementation such as `Switon\Cli\Console`.

## Keep in Mind {#keep-in-mind}

- `ProgressBar` is meant for one live terminal flow. Sharing one instance across concurrent tasks produces interleaved output.
- `TerminalWidthDetector` caches by design; long-lived processes that handle resize signals should call `clearCache()`.
- `Colors` are bitmasks, not named themes; build presets in your app if you need named styles.

## Next {#next}

- **To print, prompt, and colorize from commands:** [CLI Package](../cli/README.md)
- **For common questions:** [FAQ](faq.md#console-faq)
- **For trade-offs and rationale:** [Design Rationale](design.md#design-rationale)
