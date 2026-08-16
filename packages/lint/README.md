# Switon Lint Package

[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's single-file lint for application services that want syntax diagnostics, and a deeper check only when one is asked for.

## Highlights

- **By extension:** each file is checked by the driver registered for its extension.
- **Syntax first:** the default check is the fast syntax tool for that language.
- **Deeper on request:** analyzers such as PHPStan or ESLint run only when a deep check is asked for, and only when that tool is found.
- **Attach a report:** a write can carry a syntax report without failing when the checker crashes.
- **Replace a language:** application config can replace a driver or drop an extension.

## Installation

```bash
composer require switon/lint
```

## Quick Start

```php
use Switon\Core\Attribute\Autowired;
use Switon\Lint\LinterInterface;

class Checker
{
    #[Autowired] protected LinterInterface $linter;

    public function check(string $path): array
    {
        return $this->linter->lint($path, static fn (): bool => false);
    }
}
```

Docs: https://github.com/switon-php/lint/blob/main/docs/README.md

## License

MIT.
