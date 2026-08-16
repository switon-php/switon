# Tooling FAQ {#tooling-faq}

Short answers for registration and one tool call.

> **Prerequisites:** [Tooling Package](README.md)

## Getting Started {#getting-started}

### How do I turn a tool on? {#register}

The built-in names are already on. Add a new name under `extra.switon.tools`, then boot. To replace or drop a built-in, set `tools` on `Switon\Tooling\ToolsInterface`. See [Quick Start](README.md#quick-start) and [Configuration](README.md#configuration).

### Which names ship with the package? {#builtin-names}

`read`, `write`, `edit`, `edits`, `ls`, `rm`, `glob`, `grep`, `bash`, `lint`, `media`, `recall`, and `sleep`. They are registered without an `extra.switon.tools` entry.

## Calls {#calls}

### What happens when the name is unknown? {#unknown}

It is left out of the tools offered for that run, and reported once. The call is not guessed from another tool.

### Where does a long result go? {#spill}

Under the thread directory, with a short preview in the reply. `recall` reads the stored text. A memory-only thread is clipped and nothing is written.

### Does a tool error abort the process? {#tool-error}

No. The error comes back as the tool result. A tool may instead pause the turn. Continuing that turn is outside this package.
