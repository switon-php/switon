---
name: switon-troubleshooting
description: Log format and Inspector—where to look when troubleshooting. Log formatting, Inspector triage, and route/status-code debugging.
---

# Troubleshooting (log + Inspector)

Use this skill when the user asks how to troubleshoot, inspect requests, view logs, or what the log format is.

**Source over docs.** Use **package source code** (vendor path, interfaces, PHPDoc, implementation) as the reference. Do
**not** treat doc paths like `docs/en/log/README.md` as the source of truth—we have source; docs can be outdated. Prefer
code and PHPDoc; point users to docs only for human-oriented tutorials.

## 1. Log (switon/log)

- **API:** Inject `Psr\Log\LoggerInterface`, call `info()`, `error()`, etc. Message can use `{key}` placeholders; pass
  context array; `exception` key → stack trace; other context → appended as JSON.
- **Default line format (text):** `[{time}][{level}][{category}][{location}] {message}`. Placeholders: `time`, `level`,
  `category`, `location`, `message`. Configurable via appender `format`.
- **Source:** `vendor/switon/log` — interfaces, appenders, formatters; PHPDoc and code are authoritative. Do not cite
  `docs/en/log/README.md` as the reference.

## 2. Inspector (switon/inspector)

- **What:** Request/response profiling. Shows request details, SQL, log entries, events, views, DI, routes. Auto-enabled
  in dev/test; disabled in production.
- **How to open:** Response header `X-Inspector-Link` contains a URL; open it in browser. Or use the URL shown in the
  header (e.g. `http://localhost:8080/?__inspector=...`).
- **JSON:** Append `.json` to the inspector key in the URL for raw data.
- **Source:** `vendor/switon/inspector` — code and PHPDoc are authoritative. Do not cite doc paths like
  `docs/en/inspector/README.md` as the reference.

## 3. HTTP status entrypoints

When the user reports only a status code, jump to the entry FQCN and follow PHPDoc Road-signs + `@see` (status code is
not unique).

| Status | Entry (FQCN / method)                                                            |
|--------|----------------------------------------------------------------------------------|
| Any    | `Switon\Http\DefaultExceptionHandler`, `Switon\Core\Exception`                   |
| 401    | `Switon\Http\UnauthorizedHandler`                                                |
| 403    | `Switon\Http\ForbiddenHandler`                                                   |
| 404    | `Switon\Http\Exception\NotFoundException` (hint: `router:resolve <path> [verb]`) |
| 405    | `Switon\Routing\Exception\MethodNotAllowedException`                             |
| 400    | `Switon\Http\Exception\BadRequestException` / `Switon\Validating\Exception\*`    |
| 429    | Rate-limit filter/listener (follow PHPDoc Road-signs)                            |

## 4. When to point where

- **Logging or log format** → §1 (log format + LoggerInterface).
- **Inspect request, SQL, or performance** → §2 (Inspector, X-Inspector-Link).
- **Status code (including 404)** → §3. If needed, use `router:resolve <path> [verb]` (see router topic in *
  *switon-php-coding** skill).
- **Breakpoints, unit tests** → normal IDE/test workflow; no framework-specific skill.
