# Admin front-end — AI agent entry (primary)

**Audience:** AI agents implementing or changing `app-admin` list pages and DSL. Humans read `docs/vue-components.md` for narrative and tables.

**Constraint:** No JS bundler. Scripts are globals + `Vue.component`; order is fixed.

---

## Load this context first (strict order)

1. This file (`public/assets/js/README-AI.md`).
2. `bootstrap.js` — `App`, `PageContext`, `ListAppOptions`, `reload` / `show_edit` (JSDoc on file).
3. `core.js` — `ajaxGet`, `ajaxPost`, axios, `getAdminPageRoot` (JSDoc).
4. `components.js` — `resolvePrimaryKey`, `m-*` / `ma-*` registrations (`@file` + key function JSDoc).
5. Optional: `docs/vue-components.md` — component prop tables when you need exact attribute names.

**Script execution order at runtime:**  
`ui.js` → `core.js` → `layout.js` → `components.js` → `bootstrap.js` → page `@section('script')`.

---

## MUST / SHOULD / NEVER

| Rule | Detail |
|------|--------|
| MUST | Put list filters under `m-navbar`; bind with `prop="..."` → **`ctx.request`**. |
| MUST | Put table columns under `m-table`; row actions via **`m-actions`** (`detail,edit,delete` → `ma-*`). |
| MUST | For reusable bulk operations, prefer **`m-selection`** + **`m-batch-button`** over page-local checkbox logic. |
| MUST | For attachment/file cells and detail rows, prefer **`m-file-preview`** instead of ad-hoc image/link branches. |
| MUST | Use **`inject: ['ctx']`** (and `parentTag` / `mode` where existing components do) for leaf `m-*` / `md-*` / `ma-*` that read page state. |
| MUST | Use **`resolvePrimaryKey(row)`** for POST/delete/detail keys; if row shape is odd, set **`AdminUiDefaults.primaryKeyResolver`** in `ui.js`. |
| SHOULD | Call **`this.ajaxGet` / `this.ajaxPost`** inside components; paths starting with `/` get **`BASE_URL`** in interceptor. |
| SHOULD | Add new public `m-*` / `md-*` / `ma-*` rows to **`docs/vue-components.md`** when shipping. |
| NEVER | Add **`window.*`** hooks for normal CRUD. Use **`ctx`** and **`AdminUiDefaults`**. |
| NEVER | Rely on Sword **`@partial`** for DRY unless explicitly required; repeat `m-*` or add **`Vue.component`** in `components.js`. |

---

## List page skeleton (copy pattern)

```html
<m-navbar>...</m-navbar>
<m-dialog mode="create">...</m-dialog>
<m-dialog mode="edit">...</m-dialog>
<m-dialog mode="detail">...</m-dialog>
<m-table>...</m-table>
@section('script')
<script>
vm = new App({ data: { topic: '...' }, methods: { ... } });
</script>
@append
```

Mount is usually in layout: `vm.$mount('#app').auto_reload()` after `new App`.

---

## API shape (list reload)

- Response JSON: success when **`code === 0`** or **`'0'`**; payload in **`data`**. Use **`isApiSuccessCode`** in JS.
- Requests get **`?ajax`** from interceptor.

---

## JSDoc map (for inline tool context)

| File | What to read |
|------|----------------|
| `core.js` | `ajaxGet`, `ajaxPost`, `registerAdminPageRoot`, `isApiSuccessCode` |
| `bootstrap.js` | `PageContext`, `ListAppOptions`, `App`, `mergeModelWithMode`, `syncUrlQuery` |
| `components.js` | `@file`, `resolvePrimaryKey`, `resolveColumnLabel`, `assertInjectedCtx` |
| `ui.js` | `AdminUiDefaults` object |

Per-`Vue.component` blocks are mostly string templates; do not require JSDoc if `vue-components.md` lists props.

---

## Pre-output checklist

- [ ] New `m-*` / `ma-*` row in `vue-components.md` if user-facing.
- [ ] No new `window` surface for CRUD.
- [ ] `inject ctx` for new leaf components matching siblings.
- [ ] Primary key covered by `resolvePrimaryKey` or `primaryKeyResolver`.
- [ ] Table bulk actions use `ctx.selectedRowKeys` through `m-batch-button`, not page-local arrays.
