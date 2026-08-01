# Command Package {#command-package}
`switon/command` is the shared discovery and metadata layer for Switon command classes. CLI reads it to render `list`, help, and short-option aliases; Beacon reads it to expose action synopses to AI tooling. Choose this package only when you need the metadata directly; most apps consume it through CLI or Beacon.

**Key features:**

- **Shared discovery:** `Switon\Command\CommandDiscoveryInterface::discover()` returns a cached command-name-to-class map.
- **Reflection-based inspection:** `Switon\Command\CommandInspectorInterface` reads command and action summaries, parameter help, and option metadata from PHPDoc and reflection.
- **Visibility metadata:** `Switon\Command\Attribute\Hidden` hides a class or action from default listings without blocking execution.
- **Tool descriptions:** `Switon\Command\Attribute\Tool` attaches a short AI-facing synopsis to an action.
- **Explicit short aliases:** `Switon\Command\Attribute\ShortOption` pins a one-letter alias when the default first-letter alias would collide.

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/command
```

## Quick Start {#quick-start}

Write a normal command class. Add metadata only where CLI listing or AI tooling needs it.

```php
namespace App\Command;

use Switon\Command\Attribute\Hidden;
use Switon\Command\Attribute\ShortOption;
use Switon\Command\Attribute\Tool;

/**
 * Report commands.
 */
final class ReportCommand
{
    /**
     * Show one report summary.
     *
     * @param string $format Output format.
     * @param bool $force Rebuild even when the current result looks fresh.
     */
    #[Tool('Returns one report summary in the requested format.')]
    public function statusAction(string $format = 'json', #[ShortOption('F')] bool $force = false): array
    {
        return ['format' => $format, 'force' => $force];
    }

    #[Hidden]
    public function rebuildAction(): void
    {
    }
}
```

`format` and `force` both start with `f`; `#[ShortOption('F')]` reserves `-F` for `force` so the alias does not collide.

## Core Concepts {#core-concepts}

### Command shape {#command-shape}

One `*Command` class is one command family. Public `*Action()` methods are runnable entries; `defaultAction()` is the conventional name for the bare command (CLI routing is implemented in `switon/cli`).

### Discovery {#discovery}

Discovery returns a cached name-to-class map and nothing else.

- Application commands come from path-based scans (`App\Command\*Command`, `App\Areas\<Area>\Command\*Command`).
- Package commands register in `composer.json` under `extra.switon.commands`.
- `discover()` does not register commands with a runtime handler. CLI and Beacon are the consumers that wire execution.
- The built-in CLI commands stay in the map even if `composer-extra` data is missing or unreadable.

### Inspection {#inspection}

`CommandInspectorInterface` reads everything from reflection and PHPDoc:

- the first sentence of the class docblock becomes the command summary;
- the first sentence of an action docblock becomes the action summary;
- `@param` text becomes parameter help;
- `getActions()` returns visible actions by default; pass `true` to include `#[Hidden]` ones;
- `getActionAiDoc()` returns the `#[Tool]` text or `null`.

### Attribute overlays {#attribute-overlays}

- `#[Hidden]` on a class or method hides it from default listings; the action is still runnable when invoked by name.
- `#[Tool('...')]` on an action attaches a short AI synopsis. Keep it one sentence focused on what the action does.
- `#[ShortOption('x')]` on a parameter pins a short alias; the package only carries this metadata, CLI applies it when parsing argv.

## Keep in Mind {#keep-in-mind}

- This package owns metadata only. Invocation, help rendering, and shell completion live in `switon/cli`.
- `#[Hidden]` hides entries from `list`, not from execution. Use `list --all` (in CLI) when triaging "missing" commands.
- If a command is not discovered, check the class name suffix first, then the scan path, then `extra.switon.commands`.

## Next {#next}

- **To run command classes:** [CLI Package](../../cli/docs/README.md)
- **To expose actions as AI tools:** [Beacon Package](../../beacon/docs/README.md)
- **For common questions:** [FAQ](faq.md#command-faq)
- **For trade-offs and rationale:** [Design Rationale](design.md#design-rationale)
