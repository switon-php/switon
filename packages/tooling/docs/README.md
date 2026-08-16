# Tooling Package {#tooling-package}

`switon/tooling` gives Switon apps a catalog of named tools and a set of built-in file, search, and shell tools. Choose it when you want **one** place to register a tool and run one call. The agent loop stays outside this package.

**Key features:**

- **Built-ins:** read, write, edit, edits, ls, rm, glob, grep, bash, lint, media, recall, and sleep are registered with the catalog.
- **Added names:** `extra.switon.tools` maps a new name to a class. A name already taken stays.
- **Replace a built-in:** `tools` on `Switon\Tooling\ToolsInterface` replaces a name. `null` drops it.
- **Large output:** a long result is written beside the thread. `recall` reads it back.
- **One call:** arguments are checked, the tool runs, and a failure comes back as the tool result.

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/tooling
```

The network and the agent loop are not part of this package.

## Quick Start {#quick-start}

`read` is already registered. It accepts an absolute path, or `@thread/` under the current thread directory. Text comes back with line numbers. PNG, JPEG, GIF, and WebP come back as media.

A package adds a name that is not already taken:

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

## Core Concepts {#core-concepts}

### Catalog {#catalog}

A **name** is what a caller asks for. The built-in names are in the catalog from the start.

Boot then walks `extra.switon.tools` and adds names that are still open. The first package to declare a new name keeps it. A toolkit can also answer a name, but a registered tool wins when both claim it.

An unknown name is omitted from the set offered to a run, and reported once.

### Call {#call}

One call checks the arguments, runs the tool, and returns the result. A thrown error becomes that result instead of escaping. A tool may also pause the turn. The loop that continues the turn lives outside this package.

Paths are absolute. A leading `~` is the current home. `read` also accepts `@thread/`.

### Large output {#large-output}

A result that is too long is stored under the thread directory. The reply keeps a short preview and a pointer. `recall` reads that stored text back. A thread that is only in memory is clipped and nothing is written.

## Configuration {#configuration}

`edit` replaces one exact snippet. `edits` applies several snippets in one file. `ls`, `glob`, and `grep` look through the tree. `rm` deletes files. `lint` checks one file. `media` attaches a file for the model to see. `sleep` waits in the process and does not open a shell.

Replace or drop a built-in in `switon.yml`. A class string replaces that name. `null` removes it. Names left out stay as shipped.

```yaml
Switon\Tooling\ToolsInterface:
  tools:
    read: App\Tool\MyReadTool
    sleep: null
```

## Keep in Mind {#keep-in-mind}

- Listing a built-in name in `extra.switon.tools` does not replace it.
- Repeating a new name does not replace the first package that declared it.
- `bash` starts a fresh shell for each call.

## Next {#next}

- **To debug registration and calls:** [FAQ](faq.md#tooling-faq)
- **To understand the trade-offs:** [Design Rationale](design.md#design-rationale)
