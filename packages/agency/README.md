# Switon Agency Package

[![Agency CI](https://img.shields.io/github/actions/workflow/status/switon-php/agency/ci.yml?branch=main&label=Agency%20CI)](https://github.com/switon-php/agency/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's agent runtime for sessions, a run loop, and plain-text prompt rendering.

## Highlights

- **Sessions:** `Agent` owns a thread, a profile, and a workspace. A root session is created with `make`, not taken from a container singleton.
- **Run loop:** `Loop` drives one delivery until it settles or `maxSteps` is reached. `Instructor::instruct()` is a one-shot turn with no inbox.
- **Profiles:** Markdown under `@agency/agents` becomes a `Profile`. `Charters` merges that profile with its segments.
- **Prompt rendering:** `PromptRenderer` interprets `{{ }}`, `{% if %}`, `{% for %}`, and `{% table %}` over plain arrays.
- **Console:** `agent:chat`, `agent:run`, `agent:prompt`, and `agent:instruct`.

## Installation

```bash
composer require switon/agency
```

## Quick Start

One reply from the shipped `assist` profile:

```bash
bash bin/console agent:run --agent=assist --message="Hello"
```

Docs: https://github.com/switon-php/agency/blob/main/docs/README.md

## License

MIT.
