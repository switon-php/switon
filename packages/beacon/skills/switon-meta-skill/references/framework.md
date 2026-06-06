# PHP / framework app-facing skills

For Beacon built-in skills about PHP and Switon framework usage in applications (e.g. Switon packages, config, API
usage). Repo-root framework-development skills in the Switon monorepo are out of scope here.

## No doc references in the body

- Do **not** reference project doc paths (e.g. `docs/en/event/README.md`, "see docs § X").
- Skill must be **self-contained**: put rules, examples, and tables in the skill. Point to **code, PHPDoc, and package**
  only.

## Point to concrete code

- Specify **which** class, interface, or directory/package to read (e.g. FQCN, namespace, or path).
- **Low quality:** generic "refer to source under vendor/switon/". **High quality:** named classes, interfaces, or
  scopes.

## Mention the relevant concepts

- Name the concepts involved (which classes, interfaces, or features the framework provides); otherwise the agent
  doesn’t know what exists.
- Then point to the concrete implementation (code).

## Packages and paths

- Package names: e.g. switon/orm, switon/http. Namespaces: e.g. `Switon\Log\*`.
- App path: `vendor/switon/{package}`.
- Package↔namespace: point to **`packages:official`**.

## Skill and references as lighthouse

- Introduce what’s commonly used; point to concrete code.
- Stay concise; avoid fragile or overly specific descriptions that age poorly.

## Location

- Beacon built-in app-facing skills live in `vendor/switon/beacon/skills/` and use the `switon-` prefix.
