---
name: switon-config-check
description: Validate Switon app configuration quickly. Use when migrating config, editing `switon.yml`, or diagnosing configuration load issues in a Switon application.
---

# Switon config check

## When to Use This Skill

- Migrating `config/*.php` to `switon.yml`
- Editing `switon.yml` (especially placeholders `${...}` / `parameters`)
- Diagnosing configuration load errors (YamlParseException / ConfigLoadException)

---

## What to Run

**Preferred (app root):**

```bash
bash bin/console config:check switon.yml config
```

**Windows fallback:**

```bash
php switon.php config:check switon.yml config
```

Second arg is optional PHP `config/` for YAML vs PHP type conflict check; omit if no `config/` dir.

By default this command resolves interface bindings via `container->get()` for better unknown-key detection. If you need
a safer, reflection-only pass:

```bash
bash bin/console config:check switon.yml config false false
```

---

## What It Checks (high-signal)

- YAML parses and can be interpolated (env + `parameters`)
- YAML root is a mapping/sequence (not a scalar)
- YAML vs PHP type conflicts: same service ID must be array+array; otherwise fail fast (matches kernel spec merge rule)

---

## Follow-up on Failures

- **Parse / interpolate error**: open `vendor/switon/kernel/spec/config/yaml.md` and
  `vendor/switon/core/spec/yaml-subset.md`.
- **Type conflict**: ensure the same top-level service ID is not defined as string in one source and mapping in the
  other; prefer mapping with `class` in YAML.

