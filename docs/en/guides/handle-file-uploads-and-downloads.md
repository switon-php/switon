# Handle file uploads and downloads {#handle-file-uploads-and-downloads}

Use this guide when one working HTTP and stored-data path now needs user-owned files, safe blob keys, and application-served downloads without turning storage into a metadata system.

## Choose the first file boundary {#file-boundary}

Start from the boundary that owns the real decision in front of you.

| Boundary | Best when | Keep in mind |
|---|---|---|
| HTTP request edge | the application must accept one uploaded file, reject bad input early, and keep controller code readable | use `RequestInterface` and `FileInterface` for file access; keep text metadata validation at the request boundary too, such as display name, declared type, or user-supplied description fields |
| blob storage | the application already knows the object key and just needs to read or write bytes safely | `BlobStoreInterface` stores bytes only; it does **not** own filename, owner, or listing behavior |
| stored metadata | users, visibility, display names, and ownership rules need querying later | keep the blob key in your own repository or data layer; store metadata there first, then query that metadata record and read bytes by key from blob storage |
| background processing | thumbnails, scanning, OCR, or conversion should not block the upload request | keep the first upload path synchronous, then move heavy work to [Add durable background work](add-durable-background-work.md#add-durable-background-work) only when latency becomes the problem |

A practical first file path often includes:

- one upload endpoint
- one metadata record with the blob key
- one authenticated or app-owned download endpoint

One route detail matters here too:

- keep controller paths prefix-free when your app already applies a global router prefix
- if the public URL shape is unclear, confirm it with `bash bin/console router:prefix` before debugging the upload or download code

## Recommended build order {#build-order}

### 1. Start after one HTTP and one stored-data path are already real {#build-real-path}

File handling is easier after your request, service, and metadata boundaries already exist.
Do not let uploads become the first explanation for routing, typed input binding, or persistence confusion.

### 2. Keep file bytes and file metadata separate {#build-separate-boundaries}

Store bytes in [Storage](../../packages/storage/docs/README.md) under one app-owned key.
Store owner id, original filename, media type, and any visibility rules in your own repository or data model.

This keeps storage small and keeps file listing or ownership queries out of the blob layer.
For the first local path, `BlobStoreInterface` is usually backed by the bundled `Switon\Storage\BlobStore` implementation. In normal app config, point `Switon\Storage\BlobStoreInterface` at one root directory in root `switon.yml`, for example `@runtime/uploads`.

### 3. Validate uploads at the HTTP edge {#build-validate-edge}

Reject missing files, bad extensions, suspicious media types, or oversized payloads as close to the request boundary as possible.
Use [Validation](../../packages/validation/docs/README.md) for text metadata and `FileInterface` metadata such as `getSize()`, `getType()`, and `getExtension()` for the uploaded file itself.

### 4. Generate an application-owned key, then write the blob {#build-key-write}

Do not use the raw client filename as your storage key.
Generate one key your application controls. To prevent directory performance bottlenecks (inode limits) when files scale, use date-based or hash-based sharding instead of flat directories or single-user directories.

For example, `avatars/<shard1>/<shard2>/<hash>.<ext>` or `attachments/<year>/<month>/<generated-id>.<ext>`.

Then write the bytes through `BlobStoreInterface`. Use `put()` for smaller in-memory bodies, or `putFile()` to safely stream a local file (like an HTTP upload temporary file) directly into the storage layer.

### 5. Persist metadata after the blob boundary is established {#build-persist-metadata}

Once the blob write succeeds, store the metadata record that points to the blob key.
If metadata persistence fails after the blob write, clean up the key immediately or reconcile it later through an app-owned cleanup path.

### 6. Serve downloads through one application route {#build-download-route}

Keep download policy in your app, not in a public filesystem path.
Look up the metadata record, authorize the caller if needed, then return the file through an HTTP download response.

### 7. Move slow post-processing out of the request path only when needed {#build-background}

The first successful upload path can stop after metadata + blob storage.
If scanning, thumbnailing, conversion, or archive generation starts to dominate latency, hand that work off to [Add durable background work](add-durable-background-work.md#add-durable-background-work).

## Minimal wiring {#minimal-wiring}

Use short examples only to show the seam between the upload request, the blob store, the metadata record, and the download response.
Application-level names like `UserFileRepository`, `AvatarService`, and `StoredFile` below are illustrative. The stable part is the Switon HTTP and storage boundary.

### Keep the upload boundary small {#wiring-upload-action}

Before the code, one storage config is usually enough:

```yaml
# switon.yml (project root)
Switon\Storage\BlobStoreInterface:
  root: '@runtime/uploads'
```

That keeps the first file path local and concrete: uploaded bytes go under one app-owned root, while your own repository still owns metadata.

The controller should accept the file and hand it to one service boundary:

```php
namespace App\Controller;

use App\Service\AvatarServiceInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Http\RequestInterface;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('')]
class AvatarController
{
    #[Autowired] protected AvatarServiceInterface $avatars;

    #[PostMapping('/avatar')]
    public function uploadAction(RequestInterface $request): array
    {
        $stored = $this->avatars->storeAvatar(42, $request->file('avatar'));

        return ['id' => $stored->id, 'name' => $stored->original_name];
    }
}
```

Keep validation and metadata rules close to this boundary, but keep the blob write itself behind one service method.

### One service owns key generation, blob write, and metadata persistence {#wiring-storage-service}

Use `BlobStoreInterface` for the bytes and your own repository for the record:

```php
use App\Repository\UserFileRepository;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Request\FileInterface;
use Switon\Storage\BlobStoreInterface;

class AvatarService implements AvatarServiceInterface
{
    #[Autowired] protected BlobStoreInterface $blobs;
    #[Autowired] protected UserFileRepository $files;

    public function storeAvatar(int $userId, FileInterface $file): object
    {
        $hash = bin2hex(random_bytes(16));
        $shard = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
        $key = 'avatars/' . $shard . '/' . $hash . '.' . $file->getExtension();
        
        // putFile() bridges the local HTTP upload to the abstract storage layer,
        // and restricts allowed extensions.
        $this->blobs->putFile($key, $file->getTempName(), 'jpg,jpeg,png,webp');

        return $this->files->create([
            'user_id' => $userId,
            'blob_key' => $key,
            'original_name' => $file->getName(),
            'content_type' => $file->getType(),
            'size' => $file->getSize(),
        ]);
    }
}
```

This keeps three responsibilities clear:

- the request owns the incoming upload
- the blob store owns bytes under a safe key
- the repository owns application metadata

### Downloads should come back through HTTP, not raw paths {#wiring-download}

Serve the download from one route after metadata lookup:

```php
namespace App\Controller;

use App\Repository\UserFileRepository;
use Switon\Core\Attribute\Autowired;
use Switon\Http\Response\AttachmentRendererInterface;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Storage\BlobStoreInterface;

#[RequestMapping('')]
class FileDownloadController
{
    #[Autowired] protected UserFileRepository $files;
    #[Autowired] protected BlobStoreInterface $blobs;

    #[GetMapping('/files/{id}/download')]
    public function downloadAction(int $id, AttachmentRendererInterface $attachment): void
    {
        $record = $this->files->findById($id);

        $attachment->render(
            $this->blobs->get($record->blob_key),
            $record->original_name,
            $record->content_type,
        );
    }
}
```

For the first path, reading with `get()` is often enough.
If payload size becomes the real problem, move more deliberately to stream-based reads and writes.

## What to avoid first {#pitfalls}

Keep the first upload/download path intentionally narrow.

Avoid these too early:

- **Using the original client filename as the storage key** — keep keys app-owned and stable
- **Putting owner, visibility, or listing rules into the blob layer** — that belongs in your own metadata store
- **Exposing raw filesystem paths in API responses or controllers** — serve downloads through HTTP responses instead
- **Doubling the public prefix in upload or download routes** — keep the controller mapping prefix-free when the app already applies a router prefix such as `/api`
- **Trusting only client-reported type or extension** — compare file metadata at the request edge before you store it
- **Doing scanning, conversion, and preview generation inside the first upload request** — move heavy work later when the synchronous path is already correct
- **Treating the first local-blob path as remote object storage design** — keep CDN, signed URLs, and provider integrations out of the first guide-sized solution

A beginner should feel this after finishing the first file path:

> I know where uploaded bytes go, where file metadata lives, and how downloads return through the application boundary.

## Go deeper {#go-deeper}

- **To connect this after the first request path:** [Build an API](build-an-api.md#build-an-api)
- **To keep file metadata in the same stored-data model:** [Persist data](persist-data.md#persist-data)
- **To go deeper on upload access and request file metadata:** [Request](../../packages/http/docs/request.md#request) · [HTTP](../../packages/http/docs/README.md)
- **To go deeper on download responses and attachments:** [Response](../../packages/http/docs/response.md#response)
- **To keep bytes on app-owned keys and roots:** [Storage](../../packages/storage/docs/README.md)
- **To validate input fields and request metadata more deliberately:** [Validation](../../packages/validation/docs/README.md)
- **To move file processing, conversion, or scanning off the request path:** [Add durable background work](add-durable-background-work.md#add-durable-background-work)
- **To protect the file path with a small, trustworthy test slice:** [Add testing to a working app](add-testing-to-a-working-app.md#add-testing-to-a-working-app)
- **To document stable upload and download contracts for clients:** [Add API docs](add-api-docs.md#add-api-docs)
