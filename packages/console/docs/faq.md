# Console FAQ {#console-faq}
Short answers for setup and common terminal-output questions.

> **Prerequisites:** [Console Package](README.md)

## Getting Started {#getting-started}

### How do I add a progress bar? {#add-progress-bar}

Inject `Switon\Console\ProgressBarInterface`, then call `start($total)`, `advance()`, and `finish()`. Skip the total only when you genuinely do not know it; the default `max` is `1`.

### How do I color terminal output? {#color-output}

Combine `Switon\Console\Colors` constants with `Switon\Core\ConsoleInterface::colorize()`. Use a `ConsoleInterface` implementation such as `Switon\Cli\Console` to render the result.

### Does this package register its own bindings? {#bindings}

No. It ships primitives only. `switon/cli` wires the interfaces for command authoring; in other contexts the application container decides.

## Behavior {#behavior}

### Why does terminal width stay the same? {#width-cache}

Width is read from `COLUMNS` and cached by the consuming layer. A resized terminal keeps the startup width unless the process re-reads the variable; set `COLUMNS` explicitly when you need a fixed width.

### Why does the progress bar jump to the end? {#bar-jumps}

`setProgress()` clamps to `max`, and `finish()` forces the last render at `100%`. Pass the real total to `start()` to avoid the apparent jump.

### Can I use this without `switon/cli`? {#without-cli}

Yes, but you still need a `Switon\Core\ConsoleInterface` implementation to actually render output.
