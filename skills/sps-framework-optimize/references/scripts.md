# Scripts

This reference documents the scripts used by **sps-framework-optimize**.

## Location

- Skill-local implementations:
  - `skills/sps-framework-optimize/scripts/use-deps-tree.php`
  - `skills/sps-framework-optimize/scripts/phpdoc-see-filter.php`
  - `skills/sps-framework-optimize/scripts/phpdoc-see-validate.php`

## `use-deps-tree.php`

Builds a **package → package** dependency graph from PHP `use Switon\...` statements, using PSR-4 ownership from `packages/<pkg>/composer.json`.

### Common usage

Reverse deps list (incoming edges), filtered to low fan-in packages:

```bash
php skills/sps-framework-optimize/scripts/use-deps-tree.php --reverse --max-in=3
```

Reverse deps for one package (shorthand allowed when unique):

```bash
php skills/sps-framework-optimize/scripts/use-deps-tree.php --reverse --package=query
php skills/sps-framework-optimize/scripts/use-deps-tree.php --reverse --package=switon/query
```

BFS tree from a root package:

```bash
php skills/sps-framework-optimize/scripts/use-deps-tree.php --root=orm --max-depth=3
```

### Output notes

- The dependency graph is approximate by design: it only scans `use` statements (fast, low false positives).
- The “tree” is a BFS traversal of a graph; repeated nodes are marked as seen.

## `phpdoc-see-filter.php`

Scans `packages/**/src/**/*Interface.php` and counts **class-level** occurrences of:

- `@see \Switon\...\Interface::method()`

### Common usage

Guardrail (fail fast for noisy interfaces):

```bash
php skills/sps-framework-optimize/scripts/phpdoc-see-filter.php --min=3
```

Audit mode (show small issues too):

```bash
php skills/sps-framework-optimize/scripts/phpdoc-see-filter.php --min=2
```

### Fix rule

For any flagged interface:

- Keep **≤ 2** method-level `@see` entries.
- Convert extras to class-level `@see` or remove.
