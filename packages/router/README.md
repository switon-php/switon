# Switon Router Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/router/ci.yml?branch=main&label=CI)](https://github.com/switon-php/router/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's route matcher for literal and dynamic paths, attribute discovery, prefix-aware matching, and route resolution
events.

## Highlights

- **Attribute-based routing:** controllers and actions declare routes in place with `#[RequestMapping]` and method
  mappings.
- **Prefix-aware discovery:** controller prefixes can be derived from names or set explicitly.
- **Path mapping rules:** `''`, relative, absolute, and array paths all map cleanly to routes.
- **View mappings:** `#[ViewGetMapping]` and related attributes can add GET views beside write actions.
- **Fast route lookup:** literal routes stay in hash lookups while dynamic ones compile to regex.
- **Match visibility:** `RouteNotFound` and `RouteParamsResolved` expose misses and parameter resolution.

## Installation

```bash
composer require switon/router
```

## Quick Start

```php
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/user')]
class UserController
{
    #[GetMapping('{id}')]
    public function showAction(int $id): array
    {
        return ['id' => $id];
    }
}
```

Docs: https://docs.switon.dev/latest/router

## License

MIT.
