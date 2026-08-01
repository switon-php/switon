# Switon Storage Package

[![Storage CI](https://img.shields.io/github/actions/workflow/status/switon-php/storage/ci.yml?branch=main&label=Storage%20CI)](https://github.com/switon-php/storage/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's blob storage layer with a filesystem-backed default implementation and a small contract for custom backends.

## Highlights

- **Small blob contract:** custom backends stay easy to swap in.
- **Filesystem storage:** objects can be stored under a root path with alias resolution.
- **Stream support:** readable and writable streams are supported.
- **File upload path:** files can be written from disk with extension restrictions.
- **Safe keys:** object keys must stay relative.

## Installation

```bash
composer require switon/storage
```

## Quick Start

```php
use Switon\Core\Attribute\Autowired;
use Switon\Storage\BlobStoreInterface;

class AvatarService
{
    #[Autowired] protected BlobStoreInterface $blobStore;

    public function save(int $userId, string $bytes): void
    {
        $this->blobStore->put("avatars/{$userId}.bin", $bytes);
    }
}
```

Docs: https://github.com/switon-php/storage/blob/main/docs/README.md

## License

MIT.
