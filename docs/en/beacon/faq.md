# Beacon FAQ {#beacon-faq}
Short answers for installation and boundary questions.

## Getting Started {#getting-started}

### What is Beacon for? {#beacon}

Beacon is the installable **app-facing** AI rules and skills bundle for Switon applications.

### Is Beacon the same as the framework-development `skills/` tree? {#root-skills}

No. Beacon is the **Composer-installed** app-facing layer. The separate framework-development `skills/` tree is for contributing to the framework itself.

### Do I load Beacon through `switon.yml`? {#switonyml}

Not for the AI bootstrap layer. Load rules and skills from `AGENTS.md` or from app-local agent configuration. Beacon's PHP commands and listener still register through the installed Composer package.

## Package Use {#package-use}

### Where do Beacon skills live after install? {#where-skills-live}

Under `vendor/switon/beacon/skills`. The `vendor/switon/beacon/standards` directory tells agents how to find your app root `AGENTS.md`.

### How do I update Beacon? {#how-to-update}

Update it like any other Composer dependency.

### What does `tool:commands` show? {#tool-commands}

The **full** CLI command surface, including families such as `code:*`, `path:*`, `packages:*`, and `composer-extra:*`.
`tool:list` shows only short `#[Tool]` summaries.

### Does Beacon log tool invocations? {#tool-invocation-log}

Yes. `ToolInvoking` events produce a **masked** audit log at `@runtime/beacon/tool.log`.

## Troubleshooting {#troubleshooting}

### My agent is not seeing Beacon rules. What should I check first? {#agent-not-seeing}

Confirm `switon/beacon` is installed, `vendor/switon/beacon/` exists on disk, and the agent bootstrap resolves that path.

### Should I edit Beacon inside `vendor/` directly? {#edit-vendor}

No. Keep app-specific rules in **app-owned** files. When Beacon itself changes, upgrade the Composer dependency or contribute upstream—do not rely on long-lived edits inside `vendor/`.
