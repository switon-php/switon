# File Area

The File area manages reusable uploaded file assets for `app-admin`.

## Scope

- File upload and asset persistence
- Static public URLs for uploaded assets
- Reusable file metadata listing for backoffice modules
- Local asset service for file metadata persistence inside `app-admin`

## Boundaries

- Keep business documents and their domain rules in their own areas; File stores generic file assets only.
- Keep storage strategy simple for now: uploaded files are written under `public/uploads/file/` and served as static files.
- Keep permissions and sharing rules out of this area until a real cross-module need appears.
- Keep scene dictionaries, MIME policy, and delete reference checks app-owned; this area owns the full upload workflow until a real cross-app reuse boundary appears.

## Runtime Asset Example

`app-admin` keeps asset metadata handling inside this area.

- `App\Areas\File\Service\AssetManager` owns asset metadata creation and deletion.
- `AssetController::uploadAction()` still owns file validation, path generation, and physical movement.
- After the file is moved, asset metadata creation goes through the local `AssetManager`.

The exact flow is:

1. validate the upload payload and scene
2. decide the final backend path
3. move the file into `public/uploads/file/...`
4. call `AssetManager::create()` with the resulting metadata
5. persist or return the created asset record

This split is deliberate: upload policy remains explicit in the area, while asset metadata rules stay in one app-owned service instead of pretending to be a framework component.

## Upload Scene

File upload keeps `scene` dictionary-backed, but the example is intentionally different from the typed-action examples used in org and rbac writes.

- `App\Areas\File\View\Asset.sword` still uses `md-select` with `dict="file.scene"` for the page filter and upload trigger.
- `App\Areas\File\Input\AssetUploadInput::$scene` uses `#[DictValue(FileScene::class)]`.
- `App\Areas\File\Entity\Asset::$scene` uses `#[DictValue(FileScene::class)]` so persisted asset metadata stays on the same business code boundary.
- `App\Areas\File\Controller\AssetController::uploadAction()` receives `AssetUploadInput`, not a typed `Dict` parameter.
- This shows the scalar-validation path for multipart upload payloads where the dict value travels with other form fields.
- Invalid scene values fail before the file is moved into storage.

## Storage Path Rule

`app-admin` keeps the storage path fully backend-owned. The client sends `scene`; the server decides the final directory and filename.

- Current pattern: `uploads/file/<scene>/<YYYYMM>/<bucket>/<uuid>.<ext>`
- `<bucket>` is the first two characters of the generated UUID.
- The persisted filename is UUID-based rather than time-based.
- Scene also owns the backend upload policy. For example, the `avatar` scene only accepts image-like files even if a caller spoofs a broader front-end `accept` value.

This is the intended sample direction for downstream apps: let business code choose scene, but let the backend own final path structure.

## Admin Closure

`m-upload` now supports dialog-field mode in addition to navbar-trigger mode.

- Use `prop` to bind the uploaded result back into the current `create` or `edit` model.
- Field mode also supports picking an existing asset from the file library.
- Default binding prefers the response field with the same name as `prop`; otherwise it falls back to `asset_id`.
- Use `urlProp`, `nameProp`, and `pathProp` when the page also keeps derived asset metadata on the form model.
- Use `scene` or `extraData.scene` so upload validation stays dictionary-backed before the file is moved.
- The upload page itself now reads scene labels through `md-text` / `md-select`, so filter, detail, and table views all use the same dict source.
- Admin profile and admin-management pages both use `scene="avatar"` so front-end preview and back-end upload policy stay aligned on the same semantic boundary.

## Delete Boundary

Asset deletion is backend-guarded.

- The File area checks whether one asset is still referenced before deleting the physical file.
- Current sample references include admin avatars.
- This keeps File reusable: pages may upload freely, but generic asset deletion still refuses to break existing business records.
- The asset page also checks references before showing the final delete confirmation, so operators get the usage reason immediately instead of learning only from a late backend failure.
