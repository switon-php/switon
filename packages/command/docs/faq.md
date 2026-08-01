# Command FAQ {#command-faq}
Short answers for discovery, visibility, and metadata.

> **Prerequisites:** [Command Package](README.md)

## Getting Started {#getting-started}

### How do I make a command discoverable? {#make-discoverable}

Name the class `*Command` and put it in a scanned app path, or list it in `composer.json` `extra.switon.commands` for package-shipped commands. See [Discovery](README.md#discovery).

### How do actions become runnable entries? {#actions-runnable}

Use public `*Action()` methods. `defaultAction()` is the conventional name for the bare-command entry; CLI routing applies the convention.

### How do I hide a support action? {#hide-action}

Put `#[Switon\Command\Attribute\Hidden]` on the class or method. The action is still runnable; it just stays out of default listings.

### How do I add an AI synopsis? {#add-tool-synopsis}

Use `#[Switon\Command\Attribute\Tool('...')]` on the action and keep the text to one sentence about what the action does.

## Metadata {#metadata}

### How do I set a short option alias? {#short-option}

Put `#[Switon\Command\Attribute\ShortOption('x')]` on the action parameter. The inspector only carries the metadata; CLI applies it when parsing argv.

### Where does help text come from? {#help-text}

The first sentence of the class docblock becomes the command summary. The first sentence of an action docblock becomes the action summary. `@param` text fills parameter help.

### Does discovery register commands? {#discover-register}

No. `CommandDiscoveryInterface::discover()` returns a cached map of names to classes. Wiring execution is the consumer's job (CLI for the console, Beacon for AI tooling).

### Why do `list`, `help`, and `completion` still appear when composer-extra fails? {#builtin-fallback}

`CommandDiscovery` keeps the built-in CLI commands in the map as a safety net so the console stays usable while you fix the composer metadata.

## Next {#next}

- **Full command-writing pattern:** [README](README.md)
- **Design boundary:** [Design Rationale](design.md#design-rationale)
- **Run commands and inspect help:** [CLI Package](https://github.com/switon-php/cli/blob/main/docs/README.md)
