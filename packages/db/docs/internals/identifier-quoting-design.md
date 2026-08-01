# Identifier Quoting Design {#identifier-quoting-design}

How the `switon/db` package keeps identifier quoting portable across MySQL, PostgreSQL, SQLite, and SQL Server.

## Overview {#overview}

Switon DB uses `[identifier]` as the internal SQL notation.  
Each adapter converts it to its native quote style at execution time.

This design lets one SQL string run on multiple backends.

---

## Core Rules {#core-rules}

1. Write identifiers with brackets, such as `[users]` and `[created_at]`.
2. Client/connection internals keep bracket syntax until query execution.
3. Adapter `convertQuotes()` translates brackets per driver.
4. Do not manually mix backticks/double quotes with bracket syntax.

---

## Why Brackets {#why-brackets}

### Portability {#portability}

| Driver     | Adapter Output | Bracket Handling                               |
|------------|----------------|------------------------------------------------|
| MySQL      | `` `name` ``   | Converted from `[name]`                        |
| SQLite     | `` `name` ``   | Converted from `[name]` in the current adapter |
| PostgreSQL | `"name"`       | Converted from `[name]`                        |
| SQL Server | `[name]`       | No conversion needed                           |

### Simpler SQL Text in PHP {#simpler-text-php}

Brackets avoid escaping complexity in PHP strings and keep cross-driver SQL readable.

---

## Conversion Flow {#conversion-flow}

1. Client-generated SQL uses bracket notation.
2. `AbstractConnection::execute()` / `query()` call adapter `convertQuotes()`.
3. Adapter-specific SQL is sent to PDO.

---

## `escapeIdentifier()` Rules {#escapeidentifier-rules}

`escapeIdentifier()` returns bracket notation only.  
It does not output MySQL/PostgreSQL-specific quotes directly.

Examples:

| Input            | Output             |
|------------------|--------------------|
| `users`          | `[users]`          |
| `schema.users`   | `[schema].[users]` |
| `[users]`        | `[users]`          |
| `[schema].users` | `[schema].[users]` |

---

## Best Practices {#best-practices}

1. Use bracket notation in manual SQL written for DB package APIs.
2. Pass plain schema/table names to high-level APIs (`insert`, `update`, `getTables`), which handle quoting.
3. Avoid double quoting such as `[[name]]` or mixed manual quoting.

---

## See also {#see-also}

- [DB Package](../README.md#read-write-paths) — cross-database usage
- [Design Rationale](../design.md#design-rationale) — broader DB design decisions
