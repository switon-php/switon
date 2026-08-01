# Switon Inspector Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/inspector/ci.yml?branch=main&label=CI)](https://github.com/switon-php/inspector/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's runtime inspector for request-scoped payload capture, collector discovery, and live debug links.

## Highlights

- **Request tracing:** the inspector hooks into the request lifecycle and response output.
- **Collector loading:** collectors can be discovered and overridden by package metadata.
- **Payload storage:** captured payloads can be stored in Redis or the filesystem.
- **Debug links:** request and response headers can expose inspector links.
- **Watch mode:** `InspectorCommand` can follow activity and filter it by app or target.

## Installation

```bash
composer require --dev switon/inspector
```

## Quick Start

```bash
curl -i http://<host>:<port>/api/users
```

Open the returned `X-Inspector-Link` URL to inspect the captured request.

Docs: https://github.com/switon-php/inspector/blob/main/docs/README.md

## License

MIT.
