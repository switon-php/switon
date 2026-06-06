# Renderer FAQ {#renderer-faq}

Short answers for `Frames`, theme lookup, and Sword templates.

> **Prerequisites:** [Renderer Package](README.md)

## Getting Started {#getting-started}

### Why does `render()` return `Frames`? {#why-frames}

Because one render call carries more than output: it also carries sections, stacks, theme, and once markers.

`RendererInterface` also exposes `exists()` for template presence checks.

### When should I reuse one `Frames` object? {#reuse-frames}

Reuse it when multiple template executions must share state. Use a fresh one when each render should be isolated.

### Can I use both `.phtml` and `.sword` in one app? {#mixed-engines}

Yes. `Renderer` picks the engine from the resolved file extension.

## Templates {#templates}

### What does `$__frames` do? {#frames-runtime}

It gives templates access to the active render transaction and its composition helpers.

### Why is `__frames` reserved? {#reserved-frames}

Renderer injects that variable automatically, so user data should use a different key.

User vars are extracted with `EXTR_SKIP`, so a colliding key is skipped rather than overwriting the runtime variable.

### Does theme lookup apply to every template? {#theme-scope}

No. Theme lookup only applies to resolved paths that contain `/View/`.

## Sword {#sword}

### When should I run `sword:compile`? {#compile-command}

Run it when you want to warm compiled Sword output ahead of traffic.

### Does Sword always stay compiled? {#sword-recompile}

No. It recompiles when output is missing, source is newer, or the app is in debug mode.

`sword:compile` clears the runtime Sword cache directory before recompiling discovered templates.

### Why did `partial()` fail? {#partial-fail}

`partial()` must run inside an active render transaction. It is not available before a top-level `render()` starts.
