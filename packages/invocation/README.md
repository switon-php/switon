# Switon Invocation Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/invocation/ci.yml?branch=main&label=CI)](https://github.com/switon-php/invocation/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's invocation hook contracts for method-level guards and interceptors.

This package defines the attributes and hook contracts; [Invoker](../invoker/README.md) executes them at runtime.

## Highlights

- **Attribute-based hooks:** guards and interceptors attach behavior directly to methods.
- **Pre-execution checks:** guarded methods can be examined before they run.
- **Around-call wrapping:** interceptors can run before, after, or on exception.
- **Reusable base hook:** `Interceptor` covers the common partial-lifecycle case.
- **Reusable invocation path:** other components can share the same call flow.

## Installation

```bash
composer require switon/invocation
```

## Quick Start

```php
use Attribute;
use ReflectionMethod;
use Switon\Invocation\Attribute\GuardInterface;
use Switon\Http\Exception\ForbiddenException;
use Switon\Http\RequestInterface;

#[Attribute(Attribute::TARGET_METHOD)]
final class IpGuard implements GuardInterface
{
    public function __construct(
        private array $ips,
    ) {
    }

    public function handle(RequestInterface $request, ReflectionMethod $method): void
    {
        if (!in_array($request->ip(), $this->ips, true)) {
            throw ForbiddenException::of('Access denied.');
        }
    }
}

final class AccountService
{
    #[IpGuard(['127.0.0.1'])]
    public function deleteAction(int $id): void
    {
    }
}
```

Docs: https://docs.switon.dev/latest/invocation

## License

MIT.
