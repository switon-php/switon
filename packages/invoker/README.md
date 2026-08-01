# Switon Invoker Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/invoker/ci.yml?branch=main&label=CI)](https://github.com/switon-php/invoker/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's invoker layer that implements `Switon\Invoking\InvokerInterface` on top of `Switon\Di\InvokerInterface`, adding
guard attributes and interceptors.

## Highlights

- **One invocation entrypoint:** callables, closures, and invokable objects all share the same path.
- **Flexible argument matching:** position, name, type, and defaults can be combined.
- **Guard support:** method guards can run before the target callable.
- **Interceptor support:** pre, post, and exception handling can wrap the call.
- **Built on DI:** the layer sits on top of the container invoker used by the framework.

## Installation

```bash
composer require switon/invoker
```

## Quick Start

```php
use Switon\Core\Attribute\Autowired;
use Switon\Invoking\InvokerInterface;

class RequestHandler
{
    #[Autowired] protected InvokerInterface $invoker;

    public function handle(object $controller, string $action, array $routeParams): mixed
    {
        return $this->invoker->invoke([$controller, $action], $routeParams);
    }
}
```

Docs: https://github.com/switon-php/invoker/blob/main/docs/README.md

## License

MIT.
