# Storage FAQ {#storage-faq}

Short answers for setup and common storage issues.

> **Prerequisites:** [Storage Package](README.md)

## Getting Started {#getting-started}

### When should I use this package? {#when-to-use}

Use it for local blobs like uploads, exports, and generated files.

### Is this package only for one local disk? {#one-local-disk}

It is designed around local filesystem paths.

### Where are files stored by default? {#default-root}

The bundled `BlobStore` uses `@runtime/storage`.

### Can I change the storage root? {#change-root}

Yes. Configure `root` under `Switon\Storage\BlobStoreInterface`.

## Behavior {#behavior}

### How do I check whether a blob exists? {#exists}

Call `exists($key)`.

### What happens when the blob is missing? {#missing-object}

`get()` and `readStream()` raise `ObjectNotFoundException`. `size()` returns `null` when the size is unavailable.

### Does `delete()` fail when the key is already missing? {#delete-missing}

No. The bundled `BlobStore` returns quietly.

### Can I store metadata such as filename or content type here? {#metadata}

Keep metadata in your own database or application state.

### Can I generate download or upload URLs from the blob store? {#urls}

No. Use your HTTP layer for that.

### Is remote object storage supported? {#remote-storage}

Not by this package.

## Troubleshooting {#troubleshooting}

### Why am I getting `MisuseException` for a key? {#bad-key}

The key is probably empty, starts with `/`, contains `..`, or needed normalization before use.

### Why did `putFile()` reject the extension? {#putfile-extension}

It checks the extension on the storage key, not the source filename.

### Why does `putFile()` reject an empty allowlist string? {#empty-allowlist}

An allowlist string is parsed and trimmed first; if it ends up empty, no extension is allowed.

### Why does write fail even though the key looks valid? {#write-fails}

Check the configured root path and filesystem permissions.

### Why are file handles accumulating in a worker? {#stream-leaks}

Close the stream returned by `readStream()`.

### Should I call `get()` or `readStream()`? {#get-vs-stream}

Use `get()` for small payloads and `readStream()` for larger ones.
