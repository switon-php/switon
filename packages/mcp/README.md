# Switon MCP Package

[![MCP CI](https://img.shields.io/github/actions/workflow/status/switon-php/mcp/ci.yml?branch=main&label=MCP%20CI)](https://github.com/switon-php/mcp/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's Model Context Protocol layer: one tool catalog served over stdio or streamable HTTP.

## Highlights

- **Tool contract:** `schema()` describes arguments and `call()` runs them.
- **One catalog:** explicit names and discovered `app/Mcp/Tool/*Tool.php` classes share the same map.
- **Light binding:** defaults, required fields, and string/integer coercion.
- **Tool failures stay in the result** so the client can read and correct them.
- **Two transports:** stdio, or streamable HTTP behind an app controller.

## Installation

```bash
composer require switon/mcp
```

## Quick Start

```php
use App\Service\OrderService;
use Switon\Core\Attribute\Autowired;
use Switon\Mcp\AbstractTool;
use Switon\Mcp\ToolSchema;

class OrderStatusTool extends AbstractTool
{
    #[Autowired] protected OrderService $orders;

    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(
            description: 'Return order status by order ID',
            properties: [
                'orderId' => ['type' => 'integer'],
            ],
            required: ['orderId'],
        );
    }

    public function call(string $name, array $arguments): mixed
    {
        return $this->orders->statusOf($arguments['orderId']);
    }
}
```

Docs: https://github.com/switon-php/mcp/blob/main/docs/README.md

## License

MIT.
