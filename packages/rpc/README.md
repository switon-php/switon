# Switon RPC Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/rpc/ci.yml?branch=main&label=CI)](https://github.com/switon-php/rpc/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's queue-backed request-reply RPC for mapped method calls, daemon workers, and reply/error handling.

## Highlights

- **Request-reply flow:** `RpcInterface::call()` sends one request and waits for the matching reply.
- **Method mapping:** RPC names can target methods or configured aliases.
- **Daemon worker:** requests can be pulled from a queue and dispatched by the invoker.
- **Clear failure types:** timeout, remote, protocol, and method failures are separated.
- **Queue-backed transport:** request handling is built around queued work.

## Installation

```bash
composer require switon/rpc
```

## Quick Start

```php
use Switon\Core\Attribute\Autowired;
use Switon\Rpc\RpcInterface;

class UserService
{
    #[Autowired] protected RpcInterface $rpc;

    public function profile(int $userId): array
    {
        return $this->rpc->call(UserProfileService::class . '::profile', ['userId' => $userId]);
    }
}

class UserProfileService
{
    public function profile(int $userId): array
    {
        return ['id' => $userId, 'name' => 'mark'];
    }
}
```

Docs: https://github.com/switon-php/rpc/blob/main/docs/README.md

## License

MIT.
