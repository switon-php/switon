# Dict Area

The Dict area is the app-admin sample for the full Switon dict usage chain: `options API`, dict-backed field validation, scalar validation, and page components consuming the same read path.

## Scope

- Dictionary types
- Dictionary items
- Reusable option APIs for `md-*` components
- Real business examples that show how app modules should consume dicts

## Boundaries

- Keep business master data such as departments, positions, roles, and menus in their own areas.
- Keep domain constants in code when they control pure internal logic; use dictionaries when the value must be selectable, validated, filtered, or operator-configurable.

## The Standardized Chain

`app-admin` treats dict as one read-side contract expressed at four touch points:

1. `options API` for page components
2. `#[DictValue(...)]` on input and entity fields for write-path safety
3. scalar validation for payloads that still travel as plain fields
4. `md-*` page components for label rendering and option loading

The important point is not the `dict_type` / `dict_item` table pair by itself. The point is that every module ends up looking similar at the action, validation, and page layers.

## Options API

- `App\Areas\Dict\Controller\ItemController::optionsAction()` exposes `/dict/item/options?type_code=<code>`.
- `App\Areas\Dict\Service\DictGuard` implements `Switon\Dict\DictReaderInterface`.
- `md-select`, `md-radio`, `md-text`, and `md-tag` all consume that same read path.

Current page examples:

- `App\Areas\File\View\Asset.sword` uses `dict="file.scene"`.
- `App\Areas\Org\View\Group.sword` uses `dict="org.group.type"` and `dict="org.status"`.
- `App\Areas\Rbac\View\Admin.sword` uses `dict="admin.status"`.
- `App\Areas\Admin\View\Profile\Index.sword` reads `admin.status` for current-account status display.

## Write-Path Validation

Use `#[DictValue(...)]` when a dict-backed value travels inside an entity or input object. The controller still receives the entity or input object, while validation resolves the scalar field against the dictionary before business code runs.

- Use a thin subclass of `Dict` as the dictionary type marker.
- Put `#[DictValue(...)]` on the scalar field that carries the submitted value.
- Use typed `Dict` action parameters only when the action itself has one standalone dict value parameter.

Current examples:

- `App\Areas\Dict\Entity\Type::$value_type` uses `DictValueType`.
- `App\Areas\Org\Entity\Department::$status`, `Position::$status`, and `Group::$status` use `OrgStatus`.
- `App\Areas\Org\Entity\Group::$type` uses `GroupType`.
- `App\Entity\Admin::$status` uses `AdminStatus`.

## Scalar Validation

Use `#[DictValue(...)]` when the dict-backed value still travels as a plain body field instead of its own action parameter.

Current example:

- `App\Areas\File\Input\AssetUploadInput::$scene` uses `#[DictValue(FileScene::class)]`.
- `App\Areas\File\Controller\AssetController::uploadAction()` then reads the already-validated scalar from `AssetUploadInput`.

This is the pattern to copy for multipart forms or payload objects where one dict field naturally belongs to the body shape.

## Page Components

Use `md-*` components when the page should render labels or options from dict data instead of repeating arrays.

- `md-select` loads remote options for form fields and filters.
- `md-radio` renders dict-backed radio groups.
- `md-text` renders dict labels in table/detail views.
- `md-tag` renders dict-backed status labels with tag styling.

`app-admin` now uses these components in both generic dict management and real business pages, so the sample is no longer limited to `common.status`.

## What app-admin Now Demonstrates

`app-admin` uses dicts as one consistent rule across three layers:

1. Page options and labels come from `/dict/item/options` through `md-*` components.
2. Input and entity fields use `#[DictValue(...)]` when scalar status, level, type, or priority values still travel inside normal payloads.
3. Controller signatures receive entities or input objects, while dict type markers keep scalar fields tied to the same type codes.

The framework-facing value is that pages, inputs, entities, and actions all speak the same type code. The `dict_type` / `dict_item` pair is only storage for that wider contract.

## Conventions

- `DictResolver` validates the incoming value against an enabled dictionary item before controller code runs.
- When a non-nullable `Dict` parameter is omitted, the resolver may use the dictionary default item.
- When a nullable `Dict` parameter is omitted, the resolver returns `null` so edit actions can skip field replacement.
- `#[DictValue(...)]` stays on the normal validation path, so labels and messages still come from the regular validation locale files under `resources/validation/`.

## What To Copy From app-admin

When another app module needs dict-backed values, copy one of these shapes instead of inventing a new one:

- Use `#[DictValue(...)]` on entity or input object fields for write actions such as status, type, level, and priority.
- Use typed `Dict` action parameters only when one standalone action parameter should be the dictionary value.
- Use `/dict/item/options` as the single option source for `md-*` components.
- Prefer real business type codes such as `admin.notice.level` over only reusing generic demo codes.
