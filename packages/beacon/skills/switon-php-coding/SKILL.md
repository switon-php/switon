---
name: switon-php-coding
description: Load for almost any php dev/debug task.
---

# PHP app coding

Use this skill for PHP feature work in this Switon app.

Session bootstrap (run first):

- `bash bin/console tool:list`
- `bash bin/console tool:entry`
- If user question is specific, run `bash bin/console tool:entry <topic>` for direct entry class.

Refs name tools only.

CLI entry (cross-platform):

- Use `bash bin/console <command>` as the canonical command form in docs/examples.
- On Windows, run the equivalent command via `php switon.php <command>`.
- `php bin/console` is invalid in this repo (`bin/console` is a bash wrapper, not a PHP script).

## Anti-fragile Guidance (PHPDoc)

When a fix reveals a repeated misuse pattern or required deep cross-file investigation, propose adding ONE short PHPDoc
line (<2 lines) near the stable entrypoint (interface/entry class). The proposal MUST include the exact candidate
sentence (symptom → entrypoint/next check, using stable anchors like package/entry class/event/config key/syntax). Ask
before writing; skip for simple changes.

**Docs:** When writing any markdown/reference, prefer **FQCN on first mention** of a class/interface/event/exception;
short names are OK after.

**DI:** Prefer `FooInterface` with concrete `Foo` in the same package (strip the `Interface` suffix) so the container
autobinds without `ServiceProvider` lines; explicit `set()` is for `Contract\` / mismatched names only.

## References

| Reference                                   | Brief                                                        |
|---------------------------------------------|--------------------------------------------------------------|
| [controller.md](references/controller.md)   | HTTP controllers.                                            |
| [router.md](references/router.md)           | HTTP routing and URL→handler resolution.                     |
| [entity.md](references/entity.md)           | Domain entities and repositories.                            |
| [relation.md](references/relation.md)       | Relations on entities (BelongsTo, HasMany, etc.).            |
| [event.md](references/event.md)             | Event definitions and categories (not reactions).            |
| [listener.md](references/listener.md)       | Listeners reacting to events (business/domain).              |
| [filter.md](references/filter.md)           | HTTP request-wide filters (non-business lifecycle concerns). |
| [interceptor.md](references/interceptor.md) | Per-action interceptors on controller actions.               |
| [view.md](references/view.md)               | Views, templates, layouts, widgets.                          |
| [validation.md](references/validation.md)   | Validation rules on entities/DTOs/forms.                     |
| [i18n.md](references/i18n.md)               | Translations, locales, message formatting.                   |
| [config.md](references/config.md)           | App configuration (config/*.php, env, Factory, filter list). |
| [command.md](references/command.md)         | CLI commands and their options.                              |
| [tool.md](references/tool.md)               | Expose commands as AI tools via `#[Tool]` and tool:list.     |
| [component.md](references/component.md)     | Switon framework components in packages/*.                   |
