# Switon Context Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/context/ci.yml?branch=main&label=CI)](https://github.com/switon-php/context/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's runtime state manager for `ContextAware` components, including HTTP request isolation and coroutine sharing.

## Highlights

- **Request isolation:** mutable state stays out of long-lived services.
- **Automatic context binding:** `getContext()` resolves the matching context for each component.
- **Coroutine sharing:** child coroutines can inherit or reset context as needed.
- **Lifecycle events:** child-context creation and propagation are observable.
- **Scoped runtime state:** `ContextManager` keeps the active context available for the current request or coroutine.

## Installation

```bash
composer require switon/context
```

## Quick Start

```php
use Switon\Core\Attribute\Autowired;
use Switon\Core\ContextAware;
use Switon\Core\ContextManagerInterface;

final class RequestStateContext
{
    public array $vars = [];
}

final class RequestState implements ContextAware
{
    #[Autowired] protected ContextManagerInterface $contextManager;

    public function getContext(): RequestStateContext
    {
        return $this->contextManager->getContext($this);
    }

    public function remember(string $key, mixed $value): void
    {
        $this->getContext()->vars[$key] = $value;
    }
}
```

Docs: https://github.com/switon-php/context/blob/main/docs/README.md

## License

MIT.
