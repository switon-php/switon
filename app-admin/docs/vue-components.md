# App Admin Vue Components
Human-oriented reference for `app-admin` (`public/assets/js/components.js`, `public/assets/js/layout.js`).

**AI agents:** start with **`public/assets/js/README-AI.md`** (rules, load order, MUST/NEVER); use this doc for prop names and examples.

## Quick Start
```html
<m-navbar>
    <m-text prop="keyword"></m-text>
    <m-select prop="status" options="statuses"></m-select>
</m-navbar>

<m-table>
    <m-index></m-index>
    <m-text prop="admin_name"></m-text>
    <m-email></m-email>
    <m-actions actions="detail,edit,delete"></m-actions>
</m-table>
```

## Core Rules
- Prefix semantics:
  - `m-*` means model-centric DSL components (structure/fields/display), not framework initials.
  - `ma-*` means model actions for row-level operations.
  - `md-*` means dictionary-aware DSL components backed by `/dict/item/options`.
- Prefixes are intentional and stable: use `m-*` for normal model/page components, `ma-*` for actions, and `md-*` for dictionary-backed components. Prop cleanup should align same-category APIs, not collapse these prefixes.
- Most `m-*` and `md-*` leaf components depend on injected `ctx`.
- `m-navbar`, `m-table`, `m-dialog`, `m-form` are the context bridge containers.
- Field titles: see [Field labels](#field-labels).
- In **`m-table`**, `m-text` / `m-id` / `m-account` / `m-email` / `m-ip` show a hover copy icon for the column value by default; use `:copyable="false"` to turn off. Navbar and dialog rows are unchanged (no copy).
- `m-index`: hover shows a tooltip with the row as formatted JSON (very long rows are truncated in the tooltip; use the copy icon for the full JSON).
- Action buttons (`ma-*`, `m-button`, `m-post-button`) hide themselves by `window.ADMIN_PERMISSIONS`, which is the server-merged final permission-code list for the current identity (`anonymous` / `authenticated` / role grants). Backend authorization is still the authority for requests; front-end display is only a UI guard.

## Prop Naming Conventions

Use the same prop words for the same concept across component families:

- Fields/display: `prop`, `label`, `width`, `min-width`, `disabled`.
- Options/selectors: `options`, `value-key`, `label-key`, `url`, `value-type`.
- Actions/buttons: `action`, `permission`, `disabled`.
- POST actions: `payload`, `confirm-text`, `reload-after`.
- Row actions: `row`, `id-prop`, `visible`.

When adding a component, choose the prefix by component category first (`m-*`, `ma-*`, `md-*`), then reuse these prop names unless the existing local pattern gives a stronger reason.

## Standard CRUD Page Template

Use this as the default shape for new admin list pages:

```html
<m-navbar>
    <ma-create></ma-create>
    <m-text prop="keyword" placeholder="名称/编码"></m-text>
    <md-select prop="status" dict="common.status" placeholder="状态"></md-select>
</m-navbar>

<m-dialog mode="create">
    <m-text prop="item_name" label="名称"></m-text>
    <md-select prop="status" label="状态" dict="common.status" value-type="int"></md-select>
</m-dialog>

<m-dialog mode="edit">
    <m-id prop="item_id"></m-id>
    <m-text prop="item_name" label="名称"></m-text>
    <md-select prop="status" label="状态" dict="common.status" value-type="int"></md-select>
</m-dialog>

<m-dialog mode="detail">
    <m-id prop="item_id"></m-id>
    <m-text prop="item_name" label="名称"></m-text>
    <md-text prop="status" label="状态" dict="common.status"></md-text>
</m-dialog>

<m-table :size="15">
    <m-index></m-index>
    <m-id prop="item_id" label="Id"></m-id>
    <m-text prop="item_name" label="名称" min-width="180" show-overflow-tooltip></m-text>
    <md-tag prop="status" label="状态" dict="common.status"></md-tag>
    <m-account prop="updated_by"></m-account>
    <m-timestamp prop="updated_at"></m-timestamp>
    <m-actions actions="detail,edit,delete"></m-actions>
</m-table>
```

Default authoring rules:

- Use `ma-create` for create dialogs and `m-actions actions="..."` for normal row operations.
- Use `m-post-button` for toolbar POST actions and `ma-post` for row POST actions.
- Set `action` on `m-button` when it triggers a backend action; set explicit `permission` only when the button is outside the current route prefix or opens a different controller. Permission inference checks `window.ADMIN_KNOWN_PERMISSIONS`, so `/foo/bar` and `/foo/bar/latest` style pages both resolve to the matching server permission code when it exists.
- Prefer `md-*` for dictionary fields and `m-*` for plain fields; do not hand-roll labels for dictionary values in page methods.
- Keep page methods for orchestration only: confirmation, extra AJAX, and local state updates. If the UI shape repeats across pages, add or extend an `m-*` / `ma-*` / `md-*` component.

## Field labels {#field-labels}

Titles for table headers, filter fields, and form rows come from the same rules. `resolveColumnLabel` uses the first match:

1. **Explicit `label` on the component** (if the leaf supports it).
2. **`ctx.label[prop]`** — shared map on the page root.
3. **`prop` string** as a last resort — **humanized** for display (e.g. `admin_name` → `admin name`; underscores and similar separators become spaces; see `humanizePropLabel` in `components.js`).

**Table column headers:** The visible header text uses the resolved label above. Hover shows the **raw field name** via the native HTML `title` attribute (`columnHeaderNativeTitle` on `Vue.prototype`), so you can align columns with API keys without changing Sword.

How **`ctx.label`** is populated (later steps only fill gaps; they do not replace a non-empty value already set for that `prop`):

1. **`AdminUiDefaults.labels`** in `ui.js` — merged when the app boots.
2. **Page `data.label`** — Vue merges on top; same key overrides the default.
3. **Inferred from slots** — each of `m-navbar`, `m-table`, and `m-dialog` runs its **own** pass (on mount/update; table/dialog defer with `$nextTick`) and walks **only that component’s** default slot. It is **not** one global preorder of the whole page. For each `m-*` with both `prop` and a non-empty `label`, a value is written to `ctx.label[prop]` only when that key is still empty (**no overwrite** of an existing non-empty title). If two regions both declare a `label` for the same `prop`, **whichever scan runs first** wins; the later one is skipped.

Prefer **per-field `label="..."`** on each `m-text` / `m-id` / `m-tag` / `m-select` / … in Sword, so titles live next to the `prop`. That value is used for the column or form row immediately, and (when combined with slot inference) can fill `ctx.label` for other regions that share the same `prop`.

**Create-first (less repetition, not a mount-order rule):** For the same `prop` in navbar, create/edit, and the table, you can **define `label` once** (often on **`m-dialog mode="create"`**) and omit duplicate `label` elsewhere that share `ctx.label` via inference. You do **not** need to put create above navbar for correctness: after all containers have mounted and their deferred scans have run, `ctx.label` reflects every inferred pair; Vue will update filters that read `ctx.label`. Putting create first was only ever about possible first-frame polish, not about the scanner “seeing everything in one batch.” Keep an explicit `label` on the table for columns that never appear on the create form (for example generated ids).

Fallback chain in practice: **component `label` → page `data.label` → `ui.js` defaults → inferred from DSL**. The last three build `ctx.label` in that order; the explicit `label` on the component wins at render time and is not copied into `ctx.label` by itself.

## Component Reference

### Containers and Layout

| Component | Main Usage | Key Props |
| --- | --- | --- |
| `m-navbar` | Filter/search container; provides `ctx` and `mode` to children | - |
| `m-table` | Table container; supports paged and array response | `data`, `size`, `sizes`, optional **`table-width`** (CSS width of the whole block, e.g. `min(100%, 1280px)`), **`fit`** (Element: stretch columns when extra space — default `true`) |
| `m-dialog` | `create` / `edit` / `detail` dialog forms with submit + reload lifecycle | `mode` |
| `m-form` | Inline form container that reuses dialog-field DSL on ordinary pages | `mode` |
| `m-menu` | Left navigation menu, loads groups from `/menu/my/index` | - |
| `my-menu-tabs` | Top tab history for menu navigation (`sessionStorage`) | - |
| `m-my-menu` | User dropdown menu (logs, password, logout, cache switcher) | - |
| `inspector-links` | Request inspector panel for recent backend inspector links | - |

### Action Components (`ma-*`)

| Component | Main Usage | Key Props |
| --- | --- | --- |
| `ma-create` | Open create dialog (`ctx.createVisible = true`) | optional `permission` |
| `ma-edit` | Open edit dialog for current row (`ctx.show_edit(row)`) | `row`, optional `permission` |
| `ma-delete` | Delete current row by resolved primary key; `beforeDelete(row)` can return `false`, or `beforeDelete(row, next)` can run async checks then call `next()` | `row`, `confirmText`, optional `permission`, optional `beforeDelete` |
| `ma-detail` | Load and show detail data for current row | `row`, `endpoint`, `beforeRequest`, optional `permission`; emits `loaded` |
| `ma-post` | Generic row POST button with optional confirm and reload | `row`, `action`, `idProp`, `type`, `icon`, `title`, `confirmText`, `visible`, `reloadAfter`, `payload`, optional `permission`; emits `success`, `reloaded` |
| `ma-enable` | Enable/disable current row | `row`, `enableAction`, `disableAction`, `confirmEnableText`, `confirmDisableText`, `beforeEnable`, `beforeDisable`, optional `permission` |
| `ma-status` | Toggle row `status` (or custom prop) via two POST actions (e.g. lock/active) | `row`, `statusProp`, `activeValue`, `deactivateAction`, `activateAction`, `deactivateValue`, `activateValue`, `reloadAfter`, optional confirms/hooks/`permission` |

### Navigation and Query Inputs

| Component | Main Usage | Key Props |
| --- | --- | --- |
| `m-text` | Text input in navbar; text display/editor in table/dialog | `prop`, `placeholder`, `copyable` (default copy in table only; `:copyable="false"` to disable), `prefix`, `suffix`, `disabled` |
| `m-textarea` | Textarea editor for dialog forms | `prop`, `rows`, `disabled` |
| `m-checkbox` | Checkbox editor for dialog forms | `prop`, `disabled` |
| `m-radio` | Radio group editor | `prop`, `data`, `disabled` |
| `m-select` | Static option selector | `prop`, `options`, `valueKey`, `labelKey`, `disabled` |
| `m-admin` | Administrator selector with built-in `/rbac/admin/options` binding; defaults to active admins and local filtering | `prop`, optional `includeLocked`, optional `options` |
| `m-role` | Single-role display via role option lookup | `prop` (default `role_id`), optional `options`, `url`, `width` |
| `m-roles` | Role checkbox group for dialog/form role assignment | `prop` (default `role_ids`), `options` (default `roles`), optional `url`, `valueKey`, `labelKey`, `disabled` |
| `md-radio` | Dictionary-backed radio group | `prop`, `dict`, optional `valueType` |
| `md-select` | Dictionary-backed selector | `prop`, `dict`, optional `valueType` |
| `m-remote-select` | Remote searchable selector | `prop`, `endpoint`, `queryKey`, `minChars`, `debounceMs`, `valueKey`, `labelKey` |
| `m-date-picker` | Date range picker sub-component | - |
| `m-date` | Date range field wrapper bound to `ctx.request[prop]` | `prop` |
| `m-datetime` | DateTime field for navbar/filter and dialog form | `prop`, `valueFormat`, `clearable`, `disabled`, optional `width` |

### Table Display

| Component | Main Usage | Key Props |
| --- | --- | --- |
| `m-index` | Index column (`#`); hover shows copy → whole row as JSON | optional `width`, `index` (same as Element index column: number offset or `($index) => number`) |
| `m-selection` | Batch-selection column for list pages | optional `width`, `selectable` |
| `m-id` | ID-like text column | `prop`, `copyable` (default on in table; `false` to hide) |
| `m-account` | Account-like text column | `prop`, `copyable` (default on in table) |
| `m-email` | Email column with wider default width | `prop` (default `email`), `copyable` (default on in table) |
| `m-ip` | IP column with external lookup for non-local IP | `prop`, `copyable` (default on in table) |
| `m-number` | Number display for primary numeric fields | `prop`, `digits`, `thousand`, `prefix`, `suffix`, optional `width` |
| `m-file-size` | File-size display from bytes (`B` / `KB` / `MB` / `GB`) | `prop`, `label`, optional `width` |
| `m-amount` | Amount display with currency symbol and negative highlight | `symbol`, `digits`, `thousand` |
| `m-bool` | Boolean display column | `trueText`, `falseText` |
| `m-present-text` | Presence/non-empty display column or detail row | `prop`, `presentText`, `emptyText`, `width` |
| `m-tag` | Status display with `el-tag` | `options`, `valueKey`, `labelKey`, `typeKey` |
| `m-enabled` | Status text column, default `0/1 => 禁用/启用` | `prop`, `options`, `valueKey`, `labelKey` |
| `m-ref-text` | Display a foreign-key value through a remote option bag | `prop`, `options`, `url`, `valueKey`, `labelKey`, `fallbackPrefix`, optional column attrs |
| `md-tag` | Dictionary-backed status tag display | `prop`, `dict` |
| `md-text` | Dictionary-backed text display column/detail | `prop`, `dict` |
| `m-percent` | Percentage-like numeric column with `%` | `prop`, `title` |
| `md-tags` | Dictionary-backed multi-tag display from array data | `prop`, `dict` |
| `m-tags` | Tag list display from array data | `prop` |
| `m-link` | Internal link column from `href + prop` | `href`, `prop` |
| `m-link-external` | Safe external link column (`rel="noopener noreferrer"`) | `prop`, `textProp`, `target` |
| `m-image` | Image thumbnail + preview | `prop`, `width`, `height` |
| `m-file-preview` | File preview entry; images preview inline, PDFs preview in detail, Office/other files keep open link | `prop`, `label`, `width`, `nameProp`, `mimeProp` |
| `m-ellipsis` | Long text truncation with tooltip | `prop`, `max` |
| `m-json` | Detail-focused JSON pretty print (`<pre>`) | `prop`, `disabled` |
| `m-pre-text` | Detail-focused multiline text display with preserved line breaks | `prop`, `label`, `lineHeight` |
| `m-json-dialog` | JSON preview trigger, opens full modal content | `prop`, `buttonText`, `title` |
| `m-timestamp` | Table: default `smart-display` → **today** `HH:mm:ss`, **else** `MM-DD HH:mm` (default column width **~112px**; time cells use **DM Mono** — pairs with body **DM Sans**; `--sw-font-mono` in `admin-theme.css`). **`fullFormat`** (default **`YYYY-MM-DD HH:mm:ss`**) → tooltip + detail. `:smart-display="false"` shows **`format`** in-cell (default full datetime; default width **~176px**). Set **`flex-column`** so the column uses **`min-width`** only and grows when `m-table` has spare width (`fit` on). | `prop`, optional `width`, `flex-column`, `smart-display`, `format`, `full-format`, `disabled` |
| `m-time` | Same as `m-timestamp` (alias). | same as `m-timestamp` |

### Pagination and Operations

| Component | Main Usage | Key Props |
| --- | --- | --- |
| `m-pager` | Pagination bound to `ctx.request` / `ctx.response` | - |
| `m-actions` | Inline + dropdown operation column (`actions="edit,delete"` + `more-actions="detail,log"`); child `ma-*` buttons infer permissions from action names | `actions`, `moreActions`, `moreText`, optional `permissions` map/string (`edit:foo::update,delete:foo::remove`) |
| `m-button` | Shared button wrapper; set `action` for RBAC inference when it triggers a backend action | optional `action`, `permission`, `size` (default `mini`) |
| `m-time-tag` | Compact tag for Unix timestamps, useful in toolbars/status rows | `value`, optional `prefix`, `type`, `size` |
| `m-post-button` | Shared POST button for toolbar/bulk actions with optional confirm and reload | `action`, `payload`, `confirmText`, `reloadAfter`, `disabled`, optional `permission`, `size` (default `mini`); emits `success`, `reloaded` |
| `m-batch-button` | Shared batch POST button; posts selected row keys from `m-table` | `action`, `selectionKey`, `payload`, `minSelected`, `confirmText`, `reloadAfter`, `permission`, `size` |
| `m-upload` | Upload trigger in navbar, or file form field in `m-dialog` / `m-form` when `prop` is set; supports upload, pick-existing, bind-back, and preview | `prop`, `scene`, `pickable`, `action`, `valueKey`, `urlProp`, `nameProp`, `pathProp`, `fieldName`, `accept`, `extraData`, `successText`, `autoReload` |

### Utility Components

| Component | Main Usage | Key Props |
| --- | --- | --- |
| `m-selector` | Low-level select adapter for `m-select`; normalizes items to `{value, label}` | `data`, `valueKey`, `labelKey` |
| `m-switch` | Dialog switch editor | `prop` |
| `m-axios-cache-switcher` | Toggle ajax cache in local storage (`axios.cache.enabled`) | - |
| `m-system-time` | Server time display with clock drift indicator | - |

## JS layering and localized state

Script order is fixed in the admin layout: `ui.js` → `core.js` → `layout.js` → `components.js` → `bootstrap.js` → page `@section('script')`.

- **Page / list state** should go through **`inject: ['ctx']`** (from `m-navbar` / `m-table` / `m-dialog`) or **`this.$root`** when the value truly lives on the App vm. Prefer `ctx` for filters, list payload, dialogs.
- **Axios interceptors** (in `core.js`) need the App root before any child `created()` runs AJAX. **`App` registers the root in `created()`** via `registerAdminPageRoot` (internal to `core.js`). Legacy pages that only set a global `vm` still work as a fallback.
- **New coupling**: avoid adding more `window.*` hooks for ordinary CRUD; reserve `window` for shell/inspector/dev toggles. Prefer `AdminUiDefaults` in `ui.js` for app-wide DSL options.

## Configuration Notes
- `ui.js`:
  - `labels`: default `ctx.label` entries (see [Field labels](#field-labels)).
  - `primaryKeyResolver(row)`: optional primary key resolver override. If unset, default is `id` (when present), else first `*_id`, else first object key — same for table `ma-*` and `show_edit` inferred edit model.
- `bootstrap.js` (App root `data`):
  - `bags`: holds option lists for `m-select` when `options="bagName"` is a string **and** the page did **not** declare `bagName` on root `data` (avoids Vue 2 root `$set` warnings). **If** computed properties, methods, or templates still read `this.roles` / `this.groups` on the page vm, **declare** `roles: []` / `groups: {}` in `data` so loads stay on those root keys (same as when those keys are declared on root `data`).
- `components.js`:
  - `resolveColumnLabel`, `humanizePropLabel`, `columnHeaderNativeTitle` (raw `prop` for header `title`), inferred label registration from `m-navbar` / `m-table` / `m-dialog` slots, tooltip defaults, and copy behavior.

## Common Pitfalls
- Using ctx-dependent components outside `m-navbar`, `m-table`, `m-dialog`, or `m-form` causes injection errors.
- Assuming all row objects share same primary key can break `ma-*` actions; configure `primaryKeyResolver` when needed.
