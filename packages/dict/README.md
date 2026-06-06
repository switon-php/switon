# Switon Dict Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/dict/ci.yml?branch=main&label=CI)](https://github.com/switon-php/dict/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's dict boundary for typed action parameters and property values.

## Highlights

- **Type-based lookup:** each dict class picks its own item set.
- **Input mapping:** routed input can become typed dict values.
- **Fallback values:** empty input can fall back to the type's default item.
- **Validated values:** scalar input can resolve to stored dict values through validation.
- **Framework wiring:** validator templates are bundled for app use.

## Installation

```bash
composer require switon/dict
```

## Quick Start

```php
use Switon\Dict\Dict;
use Switon\Dict\Attribute\DictValue;

final class OrderStatus extends Dict
{
    public static function typeCode(): ?string
    {
        return 'order.status';
    }
}

final class OrderController
{
    public function updateAction(OrderStatus $status): void
    {
        $code = $status->getItemCode();
        $label = $status->getItemName();
        $value = $status->getValue();
    }
}
```

Docs: https://docs.switon.dev/latest/dict

## License

MIT.
