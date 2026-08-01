# Storage Package {#storage-package}

`switon/storage` stores local blobs under one root path.

**Key features:**

- **Local blob API:** `exists`, `get`, `put`, `delete`, `size`, `readStream`, `writeStream`, and `putFile`
- **Root-based storage:** `BlobStore` keeps keys under one configured root
- **Safe keys:** keys stay relative and cannot escape the root
- **Streams and files:** use string, stream, or local file access as needed
- **Typed failures:** missing blobs, invalid usage, storage errors, and extension rejections fail separately

---

## Installation {#installation}

**Requirements:** PHP 8.3+

```bash
composer require switon/storage
```

---

## Quick Start {#quick-start}

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

---

## Core Concepts {#core-concepts}

### Keys {#keys}

- Keys are relative blob names such as `avatars/42.bin`.
- Keys must not start with `/`.
- Keys must not contain `..`.
- Empty keys are rejected.
- Keys are trimmed and `\` is normalized to `/` before storage access.

### Root {#root}

- `BlobStore` stores keys under one root path.
- The default root is `@runtime/storage`.
- Use one store per root when policies differ.

### Access Modes {#access-modes}

- `get()` and `put()` are the normal whole-body API.
- `readStream()` and `writeStream()` are for larger payloads; `readStream()` resolves the local path and opens it directly.
- `putFile()` copies from a local file path and checks the key's extension against the allowlist.

### Errors {#security-and-errors}

- `ObjectNotFoundException` means the blob is missing.
- `StorageException` means the backend failed.
- `MisuseException` means the key or stream usage was invalid.
- `ExtensionNotAllowedException` means the file extension was rejected.

---

## Configuration {#configuration}

Most apps only need one root.

```yaml
Switon\Storage\BlobStoreInterface:
  root: '@runtime/storage'
```

### Option Discovery {#option-discovery}

Use the current option surface directly:

```bash
bash bin/console config:options 'Switon\Storage\BlobStoreInterface'
```

---

## Keep in Mind {#keep-in-mind}

- Storage is key-addressed, not inventory-based.
- Keep metadata in your own tables.
- `readStream()` returns a resource that you must close.
- `writeStream()` does not close the input handle, but it does close the output handle it opens.
- `putFile()` is a convenience path, not a file-management system.

---

## Next {#next}

- **Need setup questions answered quickly:** [FAQ](faq.md#storage-faq)
- **Need design trade-offs:** [Design Rationale](design.md#design-rationale)
