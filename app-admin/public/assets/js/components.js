/**
 * Admin DSL components and shared helpers.
 * Road-sign: m-* / ma-* component registration and row primary-key resolution start here.
 */

/** Milliseconds before Element UI tooltips open (overflow, m-ellipsis, m-index JSON, m-timestamp, …). Higher avoids flashes on quick mouse pass. */
var ADMIN_TOOLTIP_OPEN_DELAY = 1000;

(function applyAdminTooltipOpenDelay() {
    if (typeof Vue === 'undefined') {
        return;
    }
    var Tooltip = Vue.component('ElTooltip');
    if (!Tooltip || !Tooltip.options || !Tooltip.options.props || !Tooltip.options.props.openDelay) {
        return;
    }
    Tooltip.options.props.openDelay.default = ADMIN_TOOLTIP_OPEN_DELAY;
})();

/**
 * Primary key field name for `ma-*` POST bodies and `show_edit` merge. Order: `AdminUiDefaults.primaryKeyResolver`, else `id`, else first `*_id`, else first key.
 * @param {Object|null|undefined} row Table row object.
 * @returns {string|null}
 */
function resolvePrimaryKey(row) {
    const data = row || {};
    const resolver = window.AdminUiDefaults && window.AdminUiDefaults.primaryKeyResolver;
    if (typeof resolver === 'function') {
        const resolved = resolver(data);
        if (typeof resolved === 'string' && Object.prototype.hasOwnProperty.call(data, resolved)) {
            return resolved;
        }
    }

    if (Object.prototype.hasOwnProperty.call(data, 'id')) {
        return 'id';
    }

    const keys = Object.keys(data);
    const idKey = keys.find((key) => key.endsWith('_id'));
    if (idKey) {
        return idKey;
    }
    return keys.length > 0 ? keys[0] : null;
}

/**
 * Display string for confirm dialogs; prefers `explicitName`, else `*_name` on row, else primary key value.
 * @param {Object} row
 * @param {string} [explicitName]
 * @returns {string}
 */
function resolveDisplayName(row, explicitName = '') {
    if (explicitName) {
        return explicitName;
    }

    const keys = Object.keys(row || {});
    const nameKey = keys.find((key) => key.includes('_name'));
    if (nameKey) {
        return row[nameKey];
    }

    const primaryKey = resolvePrimaryKey(row);
    return primaryKey ? row[primaryKey] : '';
}

function normalizeSelectorItems(data) {
    if (Array.isArray(data)) {
        if (data.length === 0) {
            return [];
        }
        if (typeof data[0] === 'object') {
            return data.map((v) => {
                let [value, label] = Object.values(v);
                return {value, label};
            });
        }
        return data.map((v) => ({value: v, label: v}));
    }

    if (typeof data === 'object' && data !== null) {
        let items = [];
        for (let key in data) {
            items.push({value: key, label: data[key]});
        }
        return items;
    }

    return [];
}

function normalizeSelectorItemsWithKeys(data, valueKey = 'value', labelKey = 'label') {
    if (Array.isArray(data)) {
        if (data.length === 0) {
            return [];
        }
        if (typeof data[0] === 'object' && data[0] !== null) {
            return data.map((item) => {
                const hasMappedKeys =
                    Object.prototype.hasOwnProperty.call(item, valueKey) &&
                    Object.prototype.hasOwnProperty.call(item, labelKey);
                if (hasMappedKeys) {
                    return {value: item[valueKey], label: item[labelKey]};
                }

                const values = Object.values(item);
                return {value: values[0], label: values[1]};
            });
        }
        return data.map((v) => ({value: v, label: v}));
    }

    return normalizeSelectorItems(data);
}

function normalizeDictItems(data) {
    if (Array.isArray(data)) {
        return data.map((item) => {
            if (item && typeof item === 'object') {
                const value = Object.prototype.hasOwnProperty.call(item, 'value')
                    ? item.value
                    : item.item_value;
                const label = Object.prototype.hasOwnProperty.call(item, 'label')
                    ? item.label
                    : item.item_name;
                return {
                    value,
                    label,
                    code: Object.prototype.hasOwnProperty.call(item, 'code') ? item.code : item.item_code,
                    tag: Object.prototype.hasOwnProperty.call(item, 'tag') ? item.tag : 0,
                    default: Object.prototype.hasOwnProperty.call(item, 'default') ? item.default : false,
                    type: Object.prototype.hasOwnProperty.call(item, 'type') ? item.type : '',
                };
            }

            return {value: item, label: String(item), code: '', tag: 0, default: false, type: ''};
        });
    }

    return [];
}

function normalizeRemoteItems(payload, valueKey = 'value', labelKey = 'label') {
    // Remote option payload compatibility: supports array, {items:[]}, {list:[]}, and plain object map.
    if (Array.isArray(payload)) {
        return normalizeSelectorItemsWithKeys(payload, valueKey, labelKey);
    }

    if (payload && typeof payload === 'object') {
        if (Array.isArray(payload.items)) {
            return normalizeSelectorItemsWithKeys(payload.items, valueKey, labelKey);
        }
        if (Array.isArray(payload.list)) {
            return normalizeSelectorItemsWithKeys(payload.list, valueKey, labelKey);
        }
    }

    return normalizeSelectorItemsWithKeys(payload, valueKey, labelKey);
}

function dictOptionsBagName(typeCode) {
    return 'dict_' + String(typeCode || '').replace(/[^A-Za-z0-9_]/g, '_');
}

function dictOptionsUrl(typeCode) {
    return '/dict/item/options?type_code=' + encodeURIComponent(String(typeCode || ''));
}

function ensureRootOptionsBag(vm, bag) {
    const root = vm && vm.$root;
    if (!root || !bag) {
        return;
    }
    if (root[bag] !== undefined) {
        return;
    }
    if (root.bags && root.bags[bag] === undefined) {
        vm.$set(root.bags, bag, []);
    }
}

function readRootOptionsBag(vm, bag) {
    const root = vm && vm.$root;
    if (!root || !bag) {
        return [];
    }
    if (root[bag] !== undefined) {
        return root[bag];
    }
    if (root.bags && root.bags[bag] !== undefined) {
        return root.bags[bag];
    }
    return [];
}

function hydrateRootOptionsBag(vm, bag, url) {
    const root = vm && vm.$root;
    if (!root || !bag || !url) {
        return;
    }

    ensureRootOptionsBag(vm, bag);

    const key = bag + '\0' + url;
    const loads = root._bagLoads || (root._bagLoads = Object.create(null));
    const loaded = root._bagsLoaded || (root._bagsLoaded = Object.create(null));

    if (loads[key] || loaded[key]) {
        return;
    }

    loads[key] = true;
    const task = vm.ajaxGet(url, (res) => {
        let payload = res;
        if (!Array.isArray(payload) && payload && typeof payload === 'object') {
            if (Array.isArray(payload.items)) {
                payload = payload.items;
            } else if (Array.isArray(payload.list)) {
                payload = payload.list;
            }
        }
        if (!Array.isArray(payload)) {
            payload = [];
        }
        if (root[bag] !== undefined) {
            vm.$set(root, bag, payload);
        } else {
            vm.$set(root.bags, bag, payload);
        }
        loaded[key] = true;
    });
    const done = () => {
        delete loads[key];
    };
    if (task && typeof task.finally === 'function') {
        task.finally(done);
    } else {
        done();
    }
}

/**
 * Throws if `ctx` missing; leaf `m-*` must sit under `m-navbar`, `m-table`, or `m-dialog`.
 * @param {string} componentName For error message.
 * @param {Object|undefined|null} ctx Injected context (`PageContext` shape from bootstrap.js).
 * @returns {void}
 */
function assertInjectedCtx(componentName, ctx) {
    // DSL contract: leaf components rely on injected ctx from m-navbar/m-table/m-dialog.
    if (!ctx) {
        throw new Error(
            `[${componentName}] missing injected ctx. Wrap this component under m-navbar, m-table, or m-dialog.`
        );
    }
}

function ensureDialogModelModeMap(ctx, mode) {
    if (!ctx.__dialogModelFields || typeof ctx.__dialogModelFields !== 'object') {
        ctx.__dialogModelFields = {create: {}, edit: {}};
    }
    if (!ctx.__dialogModelFields[mode] || typeof ctx.__dialogModelFields[mode] !== 'object') {
        ctx.__dialogModelFields[mode] = {};
    }
    return ctx.__dialogModelFields[mode];
}

function registerDialogFormField(vm, fallbackValue = '') {
    if (!vm || vm.parentTag !== 'm-dialog') {
        return;
    }
    if (vm.mode !== 'create' && vm.mode !== 'edit') {
        return;
    }
    if (!vm.ctx || typeof vm.ctx !== 'object') {
        return;
    }
    if (!vm.prop || typeof vm.prop !== 'string') {
        return;
    }

    const propsData = vm.$options && vm.$options.propsData ? vm.$options.propsData : {};
    const vnodeData = vm.$vnode && vm.$vnode.data ? vm.$vnode.data : {};
    const vnodeAttrs = vnodeData.attrs || {};
    const hasPropDefault = Object.prototype.hasOwnProperty.call(propsData, 'default');
    const hasAttrDefault = Object.prototype.hasOwnProperty.call(vnodeAttrs, 'default');
    let value = fallbackValue;
    if (hasPropDefault) {
        value = vm.default;
    } else if (hasAttrDefault) {
        value = vnodeAttrs.default;
    }

    const map = ensureDialogModelModeMap(vm.ctx, vm.mode);
    map[vm.prop] = value;
}

function registerDialogFormFieldByExpression(ctx, mode, expression, fallbackValue = '') {
    if (!ctx || typeof ctx !== 'object') {
        return;
    }
    if (mode !== 'create' && mode !== 'edit') {
        return;
    }
    if (typeof expression !== 'string') {
        return;
    }
    const match = expression.match(/^(create|edit)\.([A-Za-z_][A-Za-z0-9_]*)$/);
    if (!match) {
        return;
    }
    if (match[1] !== mode) {
        return;
    }
    const prop = match[2];
    const map = ensureDialogModelModeMap(ctx, mode);
    if (!Object.prototype.hasOwnProperty.call(map, prop)) {
        map[prop] = fallbackValue;
    }
}

function registerDialogFormFieldByName(ctx, mode, prop, fallbackValue = '', overwrite = false) {
    if (!ctx || typeof ctx !== 'object') {
        return;
    }
    if (mode !== 'create' && mode !== 'edit') {
        return;
    }
    if (!prop || typeof prop !== 'string') {
        return;
    }
    const map = ensureDialogModelModeMap(ctx, mode);
    if (overwrite || !Object.prototype.hasOwnProperty.call(map, prop)) {
        map[prop] = fallbackValue;
    }
}

function parseLocationQuery(root) {
    const raw = window.location.search && window.location.search.length > 1 ? window.location.search.substring(1) : '';
    if (root && root.$qs && typeof root.$qs.parse === 'function') {
        return root.$qs.parse(raw) || {};
    }
    return {};
}

function isNavbarFilterValueEmpty(v) {
    return isEmptyUrlQueryValue(v);
}

/**
 * Ensure ctx.request[prop] exists for navbar filters (no coupling to create/dialog).
 * Creates request from current URL query when absent; otherwise {} so reload does not invent page/size.
 * Default initial value: same as dialog — `default` prop or tag attribute `default="..."` (see registerDialogFormField).
 */
function registerNavbarRequestField(vm, fallbackValue = '') {
    if (!vm || vm.parentTag !== 'm-navbar') {
        return;
    }
    if (!vm.ctx || typeof vm.ctx !== 'object') {
        return;
    }
    if (!vm.prop || typeof vm.prop !== 'string') {
        return;
    }
    const qs = parseLocationQuery(vm.$root);
    let req = vm.ctx.request;
    if (req == null || typeof req !== 'object' || Array.isArray(req)) {
        vm.$root.request = Object.keys(qs).length > 0 ? Object.assign({}, qs) : {};
        req = vm.ctx.request;
    }
    if (req == null || typeof req !== 'object') {
        return;
    }
    const propsData = vm.$options && vm.$options.propsData ? vm.$options.propsData : {};
    const vnodeData = vm.$vnode && vm.$vnode.data ? vm.$vnode.data : {};
    const vnodeAttrs = vnodeData.attrs || {};
    const hasPropDefault = Object.prototype.hasOwnProperty.call(propsData, 'default');
    const hasAttrDefault = Object.prototype.hasOwnProperty.call(vnodeAttrs, 'default');
    let value = fallbackValue;
    if (hasPropDefault) {
        value = vm.default;
    } else if (hasAttrDefault) {
        value = vnodeAttrs.default;
    }
    if (!Object.prototype.hasOwnProperty.call(req, vm.prop)) {
        if (Object.prototype.hasOwnProperty.call(qs, vm.prop)) {
            const qsVal = qs[vm.prop];
            if (
                isKeepEmptyQueryKey(vm.prop) &&
                (qsVal === '' || (typeof qsVal === 'string' && qsVal.trim() === ''))
            ) {
                vm.$set(req, vm.prop, '');
                return;
            }
            if (qsVal !== undefined && qsVal !== null && !(typeof qsVal === 'string' && qsVal.trim() === '')) {
                vm.$set(req, vm.prop, qsVal);
                return;
            }
        }
        vm.$set(req, vm.prop, value);
    }
    if (qs && typeof qs === 'object' && Object.prototype.hasOwnProperty.call(qs, vm.prop)) {
        const qsVal = qs[vm.prop];
        const cur = req[vm.prop];
        if (
            isKeepEmptyQueryKey(vm.prop) &&
            (qsVal === '' || (typeof qsVal === 'string' && qsVal.trim() === ''))
        ) {
            vm.$set(req, vm.prop, '');
            return;
        }
        if (isNavbarFilterValueEmpty(cur) && !isNavbarFilterValueEmpty(qsVal)) {
            vm.$set(req, vm.prop, qsVal);
        }
    }
}

function registerLeafFieldDefaults(vm, fallbackValue = '') {
    registerDialogFormField(vm, fallbackValue);
    registerNavbarRequestField(vm, fallbackValue);
}

/**
 * Display label when only `prop` is available: underscores → spaces (e.g. `created_at` → `created at`).
 * @param {string|number|undefined|null} prop
 * @returns {string}
 */
function humanizePropLabel(prop) {
    if (prop == null || prop === '') {
        return '';
    }
    return String(prop).replace(/_/g, ' ');
}

/**
 * Column or form label: explicit `label` wins, then `ctx.label[prop]`, then humanized `prop`, then `fallback`.
 * @param {Object|null|undefined} ctx
 * @param {string|undefined} label
 * @param {string|undefined} prop
 * @param {string} [fallback]
 * @returns {string}
 */
function resolveColumnLabel(ctx, label, prop, fallback = '') {
    if (label) {
        return label;
    }
    if (ctx && ctx.label && prop && Object.prototype.hasOwnProperty.call(ctx.label, prop)) {
        return ctx.label[prop];
    }
    if (prop) {
        return humanizePropLabel(prop);
    }
    return fallback;
}

/**
 * Walk one container’s slot tree (caller passes `nodes` from that container only — not a full-page scan).
 * Any m-* with both `prop` and explicit `label` registers into root.label when that key is still empty
 * (does not overwrite existing non-empty).
 */
function registerInferredFieldLabelsFromMComponents(root, nodes) {
    if (!root || !nodes) {
        return;
    }
    const visit = (list) => {
        (list || []).forEach((vnode) => {
            if (!vnode) {
                return;
            }
            const comp = vnode.componentOptions;
            const tag = comp && comp.tag ? String(comp.tag) : '';
            const propsData = comp && comp.propsData ? comp.propsData : {};
            const attrs = vnode.data && vnode.data.attrs ? vnode.data.attrs : {};
            if (tag.startsWith('m-') && typeof propsData.prop === 'string' && propsData.prop !== '') {
                let raw = propsData.label;
                if (raw === undefined && attrs) {
                    raw = attrs.label;
                }
                if (typeof raw === 'string' && raw.trim() !== '') {
                    const prop = propsData.prop;
                    const v = raw.trim();
                    const has = Object.prototype.hasOwnProperty.call(root.label, prop);
                    const cur = has ? root.label[prop] : undefined;
                    const occupied =
                        has &&
                        cur !== undefined &&
                        cur !== null &&
                        String(cur).trim() !== '';
                    if (!occupied) {
                        root.$set(root.label, prop, v);
                    }
                }
            }
            if (Array.isArray(vnode.children)) {
                visit(vnode.children);
            }
            const child = vnode.componentInstance;
            if (child && child.$slots) {
                Object.keys(child.$slots).forEach((slotName) => visit(child.$slots[slotName]));
            }
        });
    };
    visit(nodes);
}

/**
 * Batch inferred-label scans per root vm and per tick.
 * Multiple containers can call in the same update cycle; run once to avoid duplicate deep traversals.
 */
function scheduleInferredFieldLabelsFromMComponents(root, nodes) {
    if (!root || !nodes) {
        return;
    }
    const queue = root.__pendingInferredLabelNodes || (root.__pendingInferredLabelNodes = []);
    if (!queue.includes(nodes)) {
        queue.push(nodes);
    }
    if (root.__inferredLabelScanScheduled) {
        return;
    }
    root.__inferredLabelScanScheduled = true;
    root.$nextTick(() => {
        const pending = root.__pendingInferredLabelNodes || [];
        root.__pendingInferredLabelNodes = [];
        root.__inferredLabelScanScheduled = false;
        pending.forEach((slotNodes) => registerInferredFieldLabelsFromMComponents(root, slotNodes));
    });
}

function resolveOverflowTooltip(attrs, defaultValue = true) {
    return attrs['show-overflow-tooltip'] !== undefined ? attrs['show-overflow-tooltip'] : defaultValue;
}

function resolveColumnWidth(provided, fallback) {
    return provided || fallback;
}

function adminTablePrefsStorageKey(tableKey) {
    return 'switon.admin.tablePrefs.' + tableKey;
}

function normalizeTablePrefs(prefs) {
    const source = prefs && typeof prefs === 'object' ? prefs : {};
    return {
        hidden: Array.isArray(source.hidden) ? source.hidden.map((item) => String(item)) : [],
        order: Array.isArray(source.order) ? source.order.map((item) => String(item)) : []
    };
}

function loadAdminTablePrefs(tableKey) {
    if (!tableKey || typeof localStorage === 'undefined') {
        return normalizeTablePrefs({});
    }
    try {
        const raw = localStorage.getItem(adminTablePrefsStorageKey(tableKey));
        return raw ? normalizeTablePrefs(JSON.parse(raw)) : normalizeTablePrefs({});
    } catch (e) {
        return normalizeTablePrefs({});
    }
}

function saveAdminTablePrefs(tableKey, prefs) {
    if (!tableKey || typeof localStorage === 'undefined') {
        return;
    }
    try {
        localStorage.setItem(adminTablePrefsStorageKey(tableKey), JSON.stringify(normalizeTablePrefs(prefs)));
    } catch (e) {
        /* ignore */
    }
}

function flattenTableSlotNodes(nodes, bucket = []) {
    (nodes || []).forEach((node) => {
        if (!node) {
            return;
        }
        if (Array.isArray(node)) {
            flattenTableSlotNodes(node, bucket);
            return;
        }
        const comp = node.componentOptions;
        if (comp && comp.tag) {
            bucket.push(node);
            return;
        }
        if (Array.isArray(node.children)) {
            flattenTableSlotNodes(node.children, bucket);
        }
    });
    return bucket;
}

function inferTableColumnLabelByTag(tag) {
    if (tag === 'm-index') {
        return '#';
    }
    if (tag === 'm-selection') {
        return adminT('table.select');
    }
    if (tag === 'm-actions') {
        return adminT('crud.operations');
    }
    return tag.replace(/^m[dab]?-/i, '');
}

function buildTableColumnMetas(root, nodes) {
    const used = Object.create(null);
    return flattenTableSlotNodes(nodes).map((node, index) => {
        const comp = node.componentOptions || {};
        const propsData = comp.propsData || {};
        const attrs = node.data && node.data.attrs ? node.data.attrs : {};
        const tag = comp.tag ? String(comp.tag) : 'column';
        const explicitKey = attrs['column-key'] || attrs.columnKey || propsData.columnKey;
        const prop = propsData.prop || attrs.prop || '';
        let key = explicitKey || prop || `${tag}:${index}`;
        if (used[key]) {
            used[key] += 1;
            key = `${key}:${used[key]}`;
        } else {
            used[key] = 1;
        }

        return {
            key,
            tag,
            label: resolveColumnLabel(
                root && root.ctx ? root.ctx : root,
                propsData.label || attrs.label,
                prop,
                inferTableColumnLabelByTag(tag)
            ),
            locked: tag === 'm-selection' || tag === 'm-actions',
            vnode: node
        };
    });
}

/** Default m-timestamp / m-time column width: smart (11px mono ~11ch); full datetime ~19ch + pad. */
function resolveAdminTimestampColumnWidth(userWidth, smartDisplay) {
    const fallback = smartDisplay ? 112 : 176;
    return resolveColumnWidth(userWidth, fallback);
}

// Templates reference these by name (e.g. m-text el-table-column :width); bind to Vue.prototype so render can resolve them.
Vue.prototype.resolveColumnWidth = resolveColumnWidth;
Vue.prototype.resolveAdminTimestampColumnWidth = resolveAdminTimestampColumnWidth;
Vue.prototype.resolveOverflowTooltip = resolveOverflowTooltip;
/** Native tooltip on table header: raw field name (`prop`) for copy / mental model. */
Vue.prototype.columnHeaderNativeTitle = function (prop) {
    if (prop == null || prop === '') {
        return '';
    }
    return String(prop);
};

function adminPermissionSet() {
    if (!window.__ADMIN_PERMISSION_SET__) {
        const set = Object.create(null);
        const state = typeof adminShellState === 'function' ? adminShellState() : null;
        const permissions = state && Array.isArray(state.permissions) ? state.permissions : [];
        permissions.forEach((permission) => {
            if (permission !== undefined && permission !== null && String(permission).trim() !== '') {
                set[String(permission).trim()] = true;
            }
        });
        window.__ADMIN_PERMISSION_SET__ = set;
    }
    return window.__ADMIN_PERMISSION_SET__;
}

function adminKnownPermissionSet() {
    if (!window.__ADMIN_KNOWN_PERMISSION_SET__) {
        const set = Object.create(null);
        const state = typeof adminShellState === 'function' ? adminShellState() : null;
        const permissions = state && Array.isArray(state.knownPermissions) ? state.knownPermissions : [];
        permissions.forEach((permission) => {
            if (permission !== undefined && permission !== null && String(permission).trim() !== '') {
                set[String(permission).trim()] = true;
            }
        });
        window.__ADMIN_KNOWN_PERMISSION_SET__ = set;
    }
    return window.__ADMIN_KNOWN_PERMISSION_SET__;
}

function adminCan(permission) {
    if (!permission) {
        return true;
    }
    const state = typeof adminShellState === 'function' ? adminShellState() : null;
    if (!state) {
        return true;
    }
    if (state.isSuperuser) {
        return true;
    }
    const set = adminPermissionSet();
    const known = adminKnownPermissionSet();
    const items = Array.isArray(permission) ? permission : String(permission).split(',');
    return items.some((item) => {
        const code = String(item || '').trim();
        if (!code) {
            return true;
        }
        if (Array.isArray(state.knownPermissions) && !known[code]) {
            return true;
        }
        if (set[code]) {
            return true;
        }
        const parts = code.split('::');
        return parts.length === 2 && !!set[parts[0] + '::*'];
    });
}

function adminCurrentPathParts() {
    let path = window.location.pathname || '';
    try {
        if (window.BASE_URL) {
            const basePath = new URL(window.BASE_URL, window.location.origin).pathname.replace(/\/+$/, '');
            if (basePath && basePath !== '/' && path.indexOf(basePath + '/') === 0) {
                path = path.slice(basePath.length);
            }
        }
    } catch (e) {
        // Keep the raw location path when BASE_URL is not a full URL/path.
    }
    return path.replace(/^\/+|\/+$/g, '').split('/').filter(Boolean);
}

function adminCurrentPermissionPrefix() {
    const parts = adminCurrentPathParts();
    if (parts.length === 0) {
        return '';
    }
    const action = parts[parts.length - 1];
    const controllerParts = action === 'index' ? parts.slice(0, -1) : parts;
    return controllerParts.length > 0 ? controllerParts.join('.') : parts.join('.');
}

function adminInferPermission(action) {
    if (!action) {
        return '';
    }
    const prefix = adminCurrentPermissionPrefix();
    if (!prefix) {
        return '';
    }
    const clean = String(action).split('?')[0].replace(/^\/+|\/+$/g, '');
    const parts = clean.split('/').filter(Boolean);
    const suffix = parts.length > 0 ? parts[parts.length - 1] : clean;
    if (!suffix) {
        return '';
    }

    const candidates = [prefix + '::' + suffix];
    const pathParts = adminCurrentPathParts();
    if (pathParts.length > 1 && pathParts[pathParts.length - 1] !== 'index') {
        candidates.push(pathParts.slice(0, -1).join('.') + '::' + suffix);
    }

    const state = typeof adminShellState === 'function' ? adminShellState() : null;
    if (state && Array.isArray(state.knownPermissions)) {
        const known = adminKnownPermissionSet();
        const matched = candidates.find(candidate => known[candidate]);
        if (matched) {
            return matched;
        }
    }

    return candidates[0];
}

function adminResolvePermission(explicitPermission, inferredPermission) {
    if (explicitPermission === false) {
        return '';
    }
    if (typeof explicitPermission === 'string' && explicitPermission.trim() !== '') {
        return explicitPermission.trim();
    }
    return inferredPermission || '';
}

Vue.prototype.adminCan = adminCan;

function parseActionPermissionMap(permissions) {
    if (!permissions) {
        return {};
    }
    if (typeof permissions === 'object') {
        return permissions;
    }

    const map = {};
    String(permissions).split(',').forEach((item) => {
        const parts = item.split(':');
        if (parts.length < 2) {
            return;
        }
        const action = parts.shift().trim();
        const permission = parts.join(':').trim();
        if (action && permission) {
            map[action] = permission;
        }
    });
    return map;
}

function getPrimaryKeyOrThrow(row, componentName) {
    const key = resolvePrimaryKey(row);
    if (!key) {
        throw new Error(`[${componentName}] cannot resolve primary key from row: ${JSON.stringify(row)}`);
    }
    return key;
}

function resolveDeleteConfirmText(confirmText, name, fallback) {
    if (typeof confirmText === 'string' && confirmText.trim() !== '') {
        return confirmText.replace(/\{name\}/g, String(name));
    }
    return fallback;
}

function resolveCopyValue(row, prop) {
    if (!row || !prop) {
        return '';
    }

    let value = row;
    for (let part of String(prop).split('.')) {
        if (value === null || value === undefined || !Object.prototype.hasOwnProperty.call(value, part)) {
            return '';
        }
        value = value[part];
    }

    if (value === null || value === undefined) {
        return '';
    }
    if (typeof value === 'object') {
        return JSON.stringify(value);
    }
    return String(value);
}

function formatFileSizeValue(value) {
    const size = Number(value || 0);
    if (!size) {
        return '0 B';
    }
    if (size < 1024) {
        return size + ' B';
    }
    if (size < 1024 * 1024) {
        return (size / 1024).toFixed(1) + ' KB';
    }
    if (size < 1024 * 1024 * 1024) {
        return (size / (1024 * 1024)).toFixed(1) + ' MB';
    }
    return (size / (1024 * 1024 * 1024)).toFixed(1) + ' GB';
}

function formatUnixTimeValue(value) {
    const ts = Number(value || 0);
    if (!ts) {
        return '-';
    }
    const d = new Date(ts * 1000);
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}

function copyRowValueWithMessage(vm, row, prop) {
    // Single copy flow for all copyable columns to keep UI behavior/messages consistent.
    const value = resolveCopyValue(row, prop);
    if (value === '') {
        vm.$message({type: 'warning', duration: 1200, message: adminT('copy.empty')});
        return;
    }

    copyTextToClipboard(value).then(() => {
        vm.$message({type: 'success', duration: 1000, message: adminT('copy.success')});
    }).catch(() => {
        vm.$message({type: 'error', duration: 1500, message: adminT('copy.failed')});
    });
}

/**
 * Copy full table row as formatted JSON (m-index column).
 * @param {Object} vm Component instance (for `$message`).
 * @param {Object} row Table row.
 * @returns {void}
 */
function copyRowJsonWithMessage(vm, row) {
    let text;
    try {
        text = JSON.stringify(row, null, 2);
    } catch (e) {
        vm.$message({type: 'error', duration: 1500, message: adminT('copy.serialize_failed')});
        return;
    }
    if (text === undefined || text === '') {
        vm.$message({type: 'warning', duration: 1200, message: adminT('copy.empty')});
        return;
    }

    copyTextToClipboard(text).then(() => {
        vm.$message({type: 'success', duration: 1000, message: adminT('copy.row_json_success')});
    }).catch(() => {
        vm.$message({type: 'error', duration: 1500, message: adminT('copy.failed')});
    });
}

/**
 * Table list copy: default on inside `m-table` (hover icon); off in navbar/dialog. Set `:copyable="false"` on a column to disable.
 * Override `resolveCopyColumnProp()` when the copied key is not `this.prop` (e.g. m-email).
 */
const mixinTableColumnCopyable = {
    inject: ['parentTag'],
    props: {
        copyable: {
            type: Boolean,
            default: true
        }
    },
    computed: {
        copyableEnabled() {
            return this.copyable === true && this.parentTag === 'm-table';
        }
    },
    methods: {
        resolveCopyColumnProp() {
            return this.prop;
        },
        copyRowValue(row) {
            copyRowValueWithMessage(this, row, this.resolveCopyColumnProp());
        }
    }
};

function copyTextToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text);
    }

    return new Promise((resolve, reject) => {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-9999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            const ok = document.execCommand('copy');
            document.body.removeChild(textArea);
            if (ok) {
                resolve();
            } else {
                reject(new Error('copy failed'));
            }
        } catch (error) {
            document.body.removeChild(textArea);
            reject(error);
        }
    });
}

function formatNumberValue(value, digits = 2, useThousand = false) {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const num = Number(value);
    if (!Number.isFinite(num)) {
        return String(value);
    }

    if (useThousand) {
        return num.toLocaleString('en-US', {
            minimumFractionDigits: digits,
            maximumFractionDigits: digits
        });
    }

    return num.toFixed(digits);
}

function normalizeStatusItems(data, valueKey = 'value', labelKey = 'label', typeKey = 'type') {
    if (Array.isArray(data)) {
        return data.map((item) => {
            if (item && typeof item === 'object') {
                return {
                    value: item[valueKey],
                    label: item[labelKey],
                    type: item[typeKey] || ''
                };
            }
            return {value: item, label: String(item), type: ''};
        });
    }

    if (data && typeof data === 'object') {
        return Object.keys(data).map((key) => ({value: key, label: data[key], type: ''}));
    }

    return [];
}

function clampNumber(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

function getAdminTimeZoneOption() {
    const w = typeof window !== 'undefined' ? window.__SWITON_ADMIN_TIME_ZONE__ : null;
    if (typeof w === 'string' && w.trim() !== '') {
        return w.trim();
    }
    const t = window.AdminUiDefaults && window.AdminUiDefaults.timeZone;
    return typeof t === 'string' && t.trim() !== '' ? t.trim() : null;
}

const adminZoneFormatterCache = Object.create(null);

function getAdminZoneDateFormatter(timeZone) {
    if (!timeZone) {
        return null;
    }
    if (adminZoneFormatterCache[timeZone]) {
        return adminZoneFormatterCache[timeZone];
    }
    adminZoneFormatterCache[timeZone] = new Intl.DateTimeFormat('en-US', {
        timeZone,
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
        weekday: 'short'
    });
    return adminZoneFormatterCache[timeZone];
}

/** First day of week for「本周」: 0–6 same as `Date.getDay()` (0=Sun). Default 1=Monday. */
function getAdminWeekStartsOn() {
    let raw = typeof window !== 'undefined' ? window.__SWITON_ADMIN_WEEK_START__ : null;
    if (raw === null || raw === undefined || raw === '') {
        raw = window.AdminUiDefaults && window.AdminUiDefaults.weekStartsOn;
    }
    const n = Number(raw);
    if (Number.isInteger(n) && n >= 0 && n <= 6) {
        return n;
    }
    return 1;
}

/**
 * Calendar Y-M-D and Sunday=0 weekday in browser local, or in `AdminUiDefaults.timeZone` when set (IANA).
 */
function getCalendarPartsInAdminZone(date) {
    const timeZone = getAdminTimeZoneOption();
    if (!timeZone) {
        return {
            y: date.getFullYear(),
            m: date.getMonth() + 1,
            d: date.getDate(),
            dow: date.getDay()
        };
    }
    try {
        const dtf = getAdminZoneDateFormatter(timeZone);
        if (!dtf) {
            throw new Error('no formatter');
        }
        if (typeof dtf.formatToParts !== 'function') {
            throw new Error('no formatToParts');
        }
        const parts = dtf.formatToParts(date);
        const map = {};
        for (let i = 0; i < parts.length; i++) {
            const p = parts[i];
            if (p.type !== 'literal') {
                map[p.type] = p.value;
            }
        }
        const wdMap = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
        const dow = wdMap[map.weekday];
        if (dow === undefined) {
            throw new Error('weekday');
        }
        return {
            y: Number(map.year),
            m: Number(map.month),
            d: Number(map.day),
            dow
        };
    } catch (e) {
        return {
            y: date.getFullYear(),
            m: date.getMonth() + 1,
            d: date.getDate(),
            dow: date.getDay()
        };
    }
}

/** Gregorian calendar add (not fixed 24h deltas — avoids DST / week edge bugs). */
function addCalendarDays(y, m, d, delta) {
    const t = Date.UTC(y, m - 1, d + delta);
    const dt = new Date(t);
    return {
        y: dt.getUTCFullYear(),
        m: dt.getUTCMonth() + 1,
        d: dt.getUTCDate()
    };
}

function ymdToPickerDate(y, m, d) {
    return new Date(y, m - 1, d);
}

/** Daterange must satisfy start <= end; swap if a shortcut ever computes them reversed. */
function pickDateRangeOrdered(picker, start, end) {
    const ta = start instanceof Date ? start.getTime() : NaN;
    const tb = end instanceof Date ? end.getTime() : NaN;
    if (!Number.isFinite(ta) || !Number.isFinite(tb)) {
        picker.$emit('pick', [start, end]);
        return;
    }
    if (ta <= tb) {
        picker.$emit('pick', [start, end]);
    } else {
        picker.$emit('pick', [end, start]);
    }
}

function pickRecentCalendarDays(picker, days) {
    const { y, m, d } = getCalendarPartsInAdminZone(new Date());
    const startYmd = addCalendarDays(y, m, d, -days);
    pickDateRangeOrdered(
        picker,
        ymdToPickerDate(startYmd.y, startYmd.m, startYmd.d),
        ymdToPickerDate(y, m, d)
    );
}

/**
 * When list is paged but root has no request yet: prefer URL query, else backend snapshot (page/size/items).
 * Does not add page/size when nothing in URL/response — non-paged pages keep request absent.
 *
 * @param {{ size?: number }} [defaults] from m-table — only when `size` is explicitly set.
 */
function ensurePagerRequestFromSnapshot(ctx, root, defaults) {
    if (ctx.request && typeof ctx.request === 'object' && Object.keys(ctx.request).length > 0) {
        return;
    }
    const cfgSize = defaults && defaults.size != null ? Number(defaults.size) : null;

    const qs = parseLocationQuery(root);
    if (Object.keys(qs).length > 0) {
        root.request = Object.assign({}, qs);
        return;
    }
    const res = ctx.response;
    if (!res || typeof res !== 'object' || Array.isArray(res)) {
        return;
    }
    const patch = {};
    if (res.page !== undefined && res.page !== null && res.page !== '') {
        patch.page = Number(res.page);
    }
    if (res.size !== undefined && res.size !== null && res.size !== '') {
        patch.size = Number(res.size);
    } else if (Array.isArray(res.items)) {
        if (cfgSize != null && cfgSize > 0) {
            patch.size = cfgSize;
        } else if (res.items.length > 0) {
            patch.size = res.items.length;
        }
    }
    if (patch.page === undefined && patch.size !== undefined) {
        patch.page = 1;
    }
    if (Object.keys(patch).length === 0) {
        return;
    }
    root.request = patch;
}

function serializePagerFilterSignature(request) {
    if (!request || typeof request !== 'object') {
        return '';
    }
    const keys = Object.keys(request)
        .filter((key) => key !== 'page' && key !== 'size')
        .sort();
    if (keys.length === 0) {
        return '';
    }

    return keys
        .map((key) => {
            const value = request[key];
            if (value === undefined) {
                return key + '\0u';
            }
            if (value === null) {
                return key + '\0n';
            }
            if (typeof value === 'object') {
                try {
                    return key + '\0j' + JSON.stringify(value);
                } catch (e) {
                    return key + '\0s' + String(value);
                }
            }
            return key + '\0s' + String(value);
        })
        .join('\x01');
}

Vue.component('m-pager', {
    inject: {
        ctx: 'ctx',
        getPagerDefaults: {
            default: () => () => ({
                size: undefined,
                sizes: [10, 15, 20, 25, 50, 100, 500, 1000]
            })
        }
    },
    created() {
        assertInjectedCtx('m-pager', this.ctx);
        ensurePagerRequestFromSnapshot(this.ctx, this.$root, this.pagerDefaults);
    },
    mounted() {
        // Pager only mounts for paged responses: fill missing `size` from backend/URL truth, optional `page-size` override, else current page length.
        const req = this.ctx.request;
        if (!req || typeof req !== 'object') {
            return;
        }
        if (!Object.prototype.hasOwnProperty.call(req, 'size')) {
            const res = this.ctx.response;
            const cfg = this.pagerDefaults.size != null ? Number(this.pagerDefaults.size) : null;
            let next = null;
            if (res && res.size != null && res.size !== '') {
                next = Number(res.size);
            } else if (cfg != null && cfg > 0) {
                next = cfg;
            } else if (res && Array.isArray(res.items) && res.items.length > 0) {
                next = res.items.length;
            }
            if (next != null && next > 0) {
                this.$set(req, 'size', next);
            }
        }
        if (typeof this.syncUrlQuery === 'function') {
            this.syncUrlQuery(req);
        }
    },
    computed: {
        pagerDefaults() {
            return typeof this.getPagerDefaults === 'function' ? this.getPagerDefaults() : {};
        },
        displayPage() {
            const r = this.ctx.request;
            const res = this.ctx.response;
            if (r && r.page != null && r.page !== '') {
                return Number(r.page);
            }
            if (res && res.page != null) {
                return Number(res.page);
            }
            return 1;
        },
        displaySize() {
            const r = this.ctx.request;
            const res = this.ctx.response;
            const cfg = this.pagerDefaults.size != null ? Number(this.pagerDefaults.size) : null;
            if (r && r.size != null && r.size !== '') {
                return Number(r.size);
            }
            if (res && res.size != null && res.size !== '') {
                return Number(res.size);
            }
            if (cfg != null && cfg > 0) {
                return cfg;
            }
            if (res && Array.isArray(res.items) && res.items.length > 0) {
                return res.items.length;
            }
            return 10;
        },
        sizesList() {
            const list = this.pagerDefaults.sizes;
            return Array.isArray(list) && list.length > 0 ? list : [10, 15, 20, 25, 50, 100, 500, 1000];
        }
    },
    methods: {
        onCurrentChange(page) {
            ensurePagerRequestFromSnapshot(this.ctx, this.$root, this.pagerDefaults);
            if (this.ctx.request) {
                this.$set(this.ctx.request, 'page', page);
            }
        },
        onSizeChange(size) {
            ensurePagerRequestFromSnapshot(this.ctx, this.$root, this.pagerDefaults);
            if (this.ctx.request) {
                this.$set(this.ctx.request, 'size', size);
                this.$set(this.ctx.request, 'page', 1);
            }
        }
    },
    template: ` 
 <el-pagination background :current-page="displayPage"
       :page-size="displaySize"
       :page-sizes="sizesList"
       @current-change="onCurrentChange"
       @size-change="onSizeChange"
       :total="ctx.response ? ctx.response.count : 0" layout="sizes,total, prev, pager, next, jumper">
</el-pagination>`,
    watch: {
        'ctx.request': {
            handler() {
                const request = this.ctx.request;
                if (!request || typeof request !== 'object') {
                    return;
                }
                const nextSignature = serializePagerFilterSignature(request);
                if (nextSignature !== this.lastFilterSignature) {
                    this.lastFilterSignature = nextSignature;
                    if (!(request.page === 1 || request.page === '1')) {
                        this.$set(request, 'page', 1);
                    }
                }
            },
            deep: true
        }
    },
    data() {
        return {
            lastFilterSignature: serializePagerFilterSignature(this.ctx.request || {})
        };
    }
});

Vue.component('m-date-picker', {
    props: ['value'],
    methods: {
        onElChange(val) {
            this.$emit('input', val);
            this.$emit('change', val);
        }
    },
    template: `
<el-date-picker v-model="time" type="daterange" 
    :start-placeholder="adminT('date.start')" :end-placeholder="adminT('date.end')" 
    value-format="yyyy-MM-dd" 
    size="mini"
    :picker-options="pickerOptions" @change="onElChange">
</el-date-picker>`,
    watch: {
        value(val) {
            this.time = val;
        }
    },
    data() {
        return {
            time: this.value,
            pickerOptions: {
                shortcuts: [
                    {
                        text: adminT('date.today'),
                        onClick(picker) {
                            const { y, m, d } = getCalendarPartsInAdminZone(new Date());
                            const day = ymdToPickerDate(y, m, d);
                            pickDateRangeOrdered(picker, day, day);
                        }
                    },
                    {
                        text: adminT('date.yesterday'),
                        onClick(picker) {
                            const { y, m, d } = getCalendarPartsInAdminZone(new Date());
                            const ymd = addCalendarDays(y, m, d, -1);
                            const day = ymdToPickerDate(ymd.y, ymd.m, ymd.d);
                            pickDateRangeOrdered(picker, day, day);
                        }
                    },
                    {
                        text: adminT('date.last_3_days'),
                        onClick(picker) {
                            pickRecentCalendarDays(picker, 3);
                        }
                    },
                    {
                        text: adminT('date.last_week'),
                        onClick(picker) {
                            pickRecentCalendarDays(picker, 7);
                        }
                    },
                    {
                        text: adminT('date.last_month'),
                        onClick(picker) {
                            pickRecentCalendarDays(picker, 30);
                        }
                    },
                    {
                        text: adminT('date.last_3_months'),
                        onClick(picker) {
                            pickRecentCalendarDays(picker, 90);
                        }
                    },
                    {
                        text: adminT('date.last_year'),
                        onClick(picker) {
                            pickRecentCalendarDays(picker, 365);
                        }
                    },
                    {
                        text: adminT('date.this_week'),
                        onClick(picker) {
                            const now = new Date();
                            const { y, m, d, dow } = getCalendarPartsInAdminZone(now);
                            const weekStart = getAdminWeekStartsOn();
                            const back = (dow - weekStart + 7) % 7;
                            const sm = addCalendarDays(y, m, d, -back);
                            pickDateRangeOrdered(
                                picker,
                                ymdToPickerDate(sm.y, sm.m, sm.d),
                                ymdToPickerDate(y, m, d)
                            );
                        }
                    },
                    {
                        text: adminT('date.this_month'),
                        onClick(picker) {
                            const { y, m, d } = getCalendarPartsInAdminZone(new Date());
                            pickDateRangeOrdered(picker, ymdToPickerDate(y, m, 1), ymdToPickerDate(y, m, d));
                        }
                    },
                    {
                        text: adminT('date.this_quarter'),
                        onClick(picker) {
                            const { y, m, d } = getCalendarPartsInAdminZone(new Date());
                            const firstMonth = Math.floor((m - 1) / 3) * 3 + 1;
                            pickDateRangeOrdered(
                                picker,
                                ymdToPickerDate(y, firstMonth, 1),
                                ymdToPickerDate(y, m, d)
                            );
                        }
                    }
                ]
            }
        };
    }
});


Vue.component('ma-create', {
    inject: ['ctx'],
    props: {
        permission: [String, Boolean]
    },
    computed: {
        canRender() {
            return adminCan(adminResolvePermission(this.permission, adminInferPermission('create')));
        }
    },
    template: `<el-button v-if="canRender" @click="openCreate" type="primary" icon="el-icon-plus" size="mini">{{ctx.createTopic || ctx.topic}}</el-button>`,
    methods: {
        openCreate() {
            this.$emit('click');
            this.ctx.createVisible = true;
        }
    }
});

Vue.component('ma-edit', {
    inject: ['ctx'],
    props: ['row', 'disabled', 'permission'],
    computed: {
        canRender() {
            return adminCan(adminResolvePermission(this.permission, adminInferPermission('edit')));
        }
    },
    template: `<el-button v-if="canRender" @click="ctx.show_edit(row);$emit('click')" size="mini" type="primary" icon="el-icon-edit" :title="adminT('crud.edit')" :disabled="disabled"></el-button>`
});

Vue.component('ma-delete', {
    inject: ['ctx'],
    props: ['row', 'confirmText', 'disabled', 'permission', 'beforeDelete'],
    computed: {
        canRender() {
            return adminCan(adminResolvePermission(this.permission, adminInferPermission('delete')));
        }
    },
    template: '<el-button v-if="canRender" @click="do_delete(row, \'\', $event)" size="mini" type="danger" icon="el-icon-delete" :title="adminT(\'crud.delete\')" :disabled="disabled"></el-button>',
    methods: {
        do_delete(row, name = '', evt = null) {
            if (this.disabled) {
                return;
            }
            let key = getPrimaryKeyOrThrow(row, 'ma-delete');
            name = resolveDisplayName(row, name);

            const proceed = () => this.confirmAndDelete(row, key, name, evt);
            if (typeof this.beforeDelete === 'function') {
                if (this.beforeDelete.length >= 2) {
                    this.beforeDelete(row, proceed);
                    return;
                }
                if (this.beforeDelete(row) === false) {
                    return;
                }
            }

            proceed();
        },
        confirmAndDelete(row, key, name, evt = null) {
            const isCtrlDelete = !!(evt && evt.ctrlKey);

            if (isCtrlDelete) {
                this.ajaxPost('delete', {[key]: row[key]}, () => this.ctx.reload());
            } else {
                const displayName = name ? name : row[key];
                const message = resolveDeleteConfirmText(
                    this.confirmText,
                    displayName,
                    adminT('crud.confirm_delete', {name: displayName})
                );
                this.$confirm(message).then(() => {
                    this.ajaxPost('delete', {[key]: row[key]}, () => this.ctx.reload());
                });
            }
        }
    }
});

Vue.component('ma-detail', {
    inject: ['ctx'],
    props: ['row', 'link', 'endpoint', 'beforeRequest', 'permission'],
    computed: {
        canRender() {
            return adminCan(adminResolvePermission(this.permission, adminInferPermission(this.endpoint || this.link || 'detail')));
        }
    },
    template: '<el-button v-if="canRender" @click="show_detail(row,link)" size="mini" type="info" icon="el-icon-more" :title="adminT(\'crud.detail\')"></el-button>',
    methods: {
        show_detail(row, action) {
            if (typeof this.beforeRequest === 'function' && this.beforeRequest(row) === false) {
                return;
            }
            this.ctx.detailVisible = true;

            let key = getPrimaryKeyOrThrow(row, 'ma-detail');
            const endpoint = this.endpoint || (action ? action : 'detail');
            this.ajaxGet(endpoint, {[key]: row[key]}, (res) => {
                this.ctx.detail = res;
                this.$emit('loaded', res, row);
            });
        }
    }
});

Vue.component('ma-post', {
    inject: ['ctx'],
    props: {
        row: {
            type: Object,
            required: true
        },
        action: {
            type: String,
            required: true
        },
        idProp: String,
        type: {
            type: String,
            default: 'primary'
        },
        icon: {
            type: String,
            default: ''
        },
        title: {
            type: String,
            default: ''
        },
        confirmText: String,
        disabled: Boolean,
        visible: {
            type: Boolean,
            default: true
        },
        reloadAfter: {
            type: Boolean,
            default: true
        },
        payload: Object,
        permission: [String, Boolean]
    },
    computed: {
        canRender() {
            return adminCan(adminResolvePermission(this.permission, adminInferPermission(this.action)));
        }
    },
    template: `
<el-button
    v-if="visible && canRender"
    size="mini"
    :type="type"
    :icon="icon"
    :title="title"
    :disabled="disabled"
    @click="submit"
></el-button>`,
    methods: {
        requestPayload() {
            if (this.payload && typeof this.payload === 'object') {
                return Object.assign({}, this.payload);
            }

            const key = this.idProp || getPrimaryKeyOrThrow(this.row, 'ma-post');
            return {[key]: this.row[key]};
        },
        submit() {
            if (this.disabled || !this.canRender) {
                return;
            }

            const request = () => {
                this.ajaxPost(this.action, this.requestPayload(), (res) => {
                    this.$emit('success', res, this.row);
                    if (this.reloadAfter) {
                        const reloaded = this.ctx.reload();
                        if (reloaded && typeof reloaded.then === 'function') {
                            reloaded.then(() => this.$emit('reloaded', res, this.row));
                        } else {
                            this.$emit('reloaded', res, this.row);
                        }
                    }
                });
            };

            if (this.confirmText) {
                const message = resolveDeleteConfirmText(
                    this.confirmText,
                    resolveDisplayName(this.row),
                    this.confirmText
                );
                this.$confirm(message).then(request);
                return;
            }

            request();
        }
    }
});

Vue.component('ma-enable', {
    inject: ['ctx'],
    props: [
        'row',
        'enableAction',
        'disableAction',
        'confirmEnableText',
        'confirmDisableText',
        'beforeEnable',
        'beforeDisable',
        'permission'
    ],
    computed: {
        canEnableRender() {
            return adminCan(adminResolvePermission(this.permission, adminInferPermission(this.enableAction || 'enable')));
        },
        canDisableRender() {
            return adminCan(adminResolvePermission(this.permission, adminInferPermission(this.disableAction || 'disable')));
        }
    },
    template: `
<el-button v-if="row.enabled && canDisableRender" @click.native.prevent="do_disable(row)" size="mini" type="danger" icon="el-icon-lock" :title="adminT('action.disable')"></el-button>
<el-button v-else-if="!row.enabled && canEnableRender" @click.native.prevent="do_enable(row)" size="mini" type="warning" icon="el-icon-unlock" :title="adminT('action.enable')"></el-button>`,
    methods: {
        do_enable(row) {
            if (!this.canEnableRender) {
                return;
            }
            if (typeof this.beforeEnable === 'function' && this.beforeEnable(row) === false) {
                return;
            }
            let key = getPrimaryKeyOrThrow(row, 'ma-enable');
            const action = this.enableAction || 'enable';
            const request = () => this.ajaxPost(action, {[key]: row[key]}, () => row.enabled = 1);

            if (this.confirmEnableText) {
                const message = resolveDeleteConfirmText(this.confirmEnableText, resolveDisplayName(row), this.confirmEnableText);
                this.$confirm(message).then(request);
            } else {
                request();
            }
        },
        do_disable(row) {
            if (!this.canDisableRender) {
                return;
            }
            if (typeof this.beforeDisable === 'function' && this.beforeDisable(row) === false) {
                return;
            }
            let key = getPrimaryKeyOrThrow(row, 'ma-enable');
            const action = this.disableAction || 'disable';
            const request = () => this.ajaxPost(action, {[key]: row[key]}, () => row.enabled = 0);

            if (this.confirmDisableText) {
                const message = resolveDeleteConfirmText(this.confirmDisableText, resolveDisplayName(row), this.confirmDisableText);
                this.$confirm(message).then(request);
            } else {
                request();
            }
        }
    }
});

/**
 * Row status toggle (non-boolean): one POST when "active", another when not (e.g. 激活 / 锁定).
 * Uses loose equality on `row[statusProp]` vs `activeValue`. Primary key from `resolvePrimaryKey`.
 *
 * @see ma-enable
 */
Vue.component('ma-status', {
    inject: ['ctx'],
    props: {
        row: {
            type: Object,
            required: true
        },
        statusProp: {
            type: String,
            default: 'status'
        },
        /** When `row[statusProp] == activeValue`, the first button (deactivate) is shown. */
        activeValue: {
            default: 1
        },
        deactivateAction: {
            type: String,
            default: 'lock'
        },
        activateAction: {
            type: String,
            default: 'active'
        },
        deactivateValue: {
            default: 2
        },
        activateValue: {
            default: 1
        },
        deactivateTitle: {
            type: String,
            default: adminT('action.lock')
        },
        activateTitle: {
            type: String,
            default: adminT('action.activate')
        },
        deactivateType: {
            type: String,
            default: 'danger'
        },
        activateType: {
            type: String,
            default: 'warning'
        },
        deactivateIcon: {
            type: String,
            default: 'el-icon-lock'
        },
        activateIcon: {
            type: String,
            default: 'el-icon-unlock'
        },
        confirmDeactivateText: String,
        confirmActivateText: String,
        beforeDeactivate: Function,
        beforeActivate: Function,
        permission: [String, Boolean],
        /** If true, run `ctx.reload()` on success; otherwise patch `row[statusProp]` only. */
        reloadAfter: {
            type: Boolean,
            default: false
        }
    },
    computed: {
        isActiveState() {
            return this.row[this.statusProp] == this.activeValue;
        },
        canDeactivateRender() {
            return adminCan(adminResolvePermission(this.permission, adminInferPermission(this.deactivateAction || 'lock')));
        },
        canActivateRender() {
            return adminCan(adminResolvePermission(this.permission, adminInferPermission(this.activateAction || 'active')));
        }
    },
    methods: {
        payloadForRow(row) {
            const key = getPrimaryKeyOrThrow(row, 'ma-status');
            return {[key]: row[key]};
        },
        afterPost(nextStatus) {
            if (this.reloadAfter) {
                this.ctx.reload();
            } else {
                this.$set(this.row, this.statusProp, nextStatus);
            }
        },
        do_deactivate(row) {
            if (!this.canDeactivateRender) {
                return;
            }
            if (typeof this.beforeDeactivate === 'function' && this.beforeDeactivate(row) === false) {
                return;
            }
            const action = this.deactivateAction || 'lock';
            const run = () =>
                this.ajaxPost(action, this.payloadForRow(row), () => this.afterPost(this.deactivateValue));

            if (this.confirmDeactivateText) {
                const message = resolveDeleteConfirmText(
                    this.confirmDeactivateText,
                    resolveDisplayName(row),
                    this.confirmDeactivateText
                );
                this.$confirm(message).then(run);
            } else {
                run();
            }
        },
        do_activate(row) {
            if (!this.canActivateRender) {
                return;
            }
            if (typeof this.beforeActivate === 'function' && this.beforeActivate(row) === false) {
                return;
            }
            const action = this.activateAction || 'active';
            const run = () =>
                this.ajaxPost(action, this.payloadForRow(row), () => this.afterPost(this.activateValue));

            if (this.confirmActivateText) {
                const message = resolveDeleteConfirmText(
                    this.confirmActivateText,
                    resolveDisplayName(row),
                    this.confirmActivateText
                );
                this.$confirm(message).then(run);
            } else {
                run();
            }
        }
    },
    template: `
<el-button v-if="isActiveState && canDeactivateRender" @click.native.prevent="do_deactivate(row)" size="mini" :type="deactivateType" :icon="deactivateIcon" :title="deactivateTitle"></el-button>
<el-button v-else-if="!isActiveState && canActivateRender" @click.native.prevent="do_activate(row)" size="mini" :type="activateType" :icon="activateIcon" :title="activateTitle"></el-button>`
});

Vue.component('m-table', {
    provide() {
        const self = this;
        return {
            parentTag: 'm-table',
            mode: '',
            ctx: this.pageCtx,
            getPagerDefaults() {
                return {
                    size: self.size,
                    sizes: self.sizes
                };
            }
        };
    },
    props: {
        data: [Array, Object],
        rowKey: {
            type: [String, Function],
            default: ''
        },
        configurable: {
            type: Boolean,
            default: true
        },
        /**
         * Optional per-page row count (matches `request.size` / query `size`). Seeds first request when set.
         * Omit to follow backend + URL + `response.size` only.
         */
        size: {
            type: Number,
            default: undefined
        },
        /** Page-size dropdown choices (Element `page-sizes`). */
        sizes: {
            type: Array,
            default() {
                return [10, 15, 20, 25, 50, 100, 500, 1000];
            }
        },
        /** CSS width of the whole table wrapper, e.g. `100%`, `min(100%, 1280px)`, `1200px`. Empty = fill parent. */
        tableWidth: {
            type: String,
            default: ''
        },
        /** Element `fit`: stretch columns to table width when there is extra space. */
        fit: {
            type: Boolean,
            default: true
        }
    },
    data() {
        return {
            prefsVisible: false,
            tablePrefsState: { hidden: [], order: [] }
        };
    },
    computed: {
        pageCtx() {
            return this.$root.ctx;
        },
        tableKey() {
            const path = typeof window !== 'undefined' && window.location ? window.location.pathname : 'table';
            return path.replace(/\/+/g, '.').replace(/^\.+|\.+$/g, '') || 'table';
        },
        mTableWrapperStyle() {
            if (!this.tableWidth) {
                return {};
            }
            return {
                width: this.tableWidth,
                maxWidth: '100%',
                boxSizing: 'border-box'
            };
        },
        isPagedMode() {
            if (this.data) {
                return false;
            }
            const response = this.pageCtx ? this.pageCtx.response : null;
            if (response && typeof response === 'object' && !Array.isArray(response)) {
                return Array.isArray(response.items) || response.page !== undefined || response.count !== undefined;
            }
            return false;
        },
        tableData() {
            if (this.data) {
                return this.data;
            }
            const response = this.pageCtx ? this.pageCtx.response : null;
            if (this.isPagedMode) {
                if (response && typeof response === 'object' && !Array.isArray(response) && Array.isArray(response.items)) {
                    return response.items;
                }
                return [];
            }
            return Array.isArray(response) ? response : [];
        },
        tableLoading() {
            const root = this.$root;
            const hasResponse = this.pageCtx && this.pageCtx.response !== undefined && this.pageCtx.response !== null;
            return !!(root && root.loading) || (!hasResponse && !(root && root.loaded));
        },
        tableColumns() {
            return buildTableColumnMetas(this.$root, this.$slots.default || []);
        },
        orderedTableColumns() {
            const orderMap = Object.create(null);
            this.tablePrefsState.order.forEach((key, index) => {
                orderMap[key] = index;
            });
            return this.tableColumns.slice().sort((a, b) => {
                const ai = orderMap[a.key];
                const bi = orderMap[b.key];
                const aKnown = ai !== undefined;
                const bKnown = bi !== undefined;
                if (aKnown && bKnown) {
                    return ai - bi;
                }
                if (aKnown) {
                    return -1;
                }
                if (bKnown) {
                    return 1;
                }
                return 0;
            });
        },
        visibleTableColumns() {
            const hidden = new Set(this.tablePrefsState.hidden);
            return this.orderedTableColumns.filter((column) => column.locked || !hidden.has(column.key));
        },
        hasSelectionColumn() {
            return this.tableColumns.some((column) => column.tag === 'm-selection');
        },
        selectedCount() {
            return Array.isArray(this.$root.selectedRowKeys) ? this.$root.selectedRowKeys.length : 0;
        }
    },
    mounted() {
        this.tablePrefsState = loadAdminTablePrefs(this.tableKey);
        this.$root.tablePrefs = Object.assign({}, this.tablePrefsState);
        this.$root._clearTableSelection = () => this.clearSelection();
        this.scheduleInferredFieldLabels();
        if (this.data) {
            return;
        }
        const n = this.size;
        if (n == null || n === '' || Number(n) <= 0) {
            return;
        }
        const req = this.pageCtx && this.pageCtx.request;
        if (!req || typeof req !== 'object') {
            return;
        }
        if (!Object.prototype.hasOwnProperty.call(req, 'size')) {
            this.$set(req, 'size', Number(n));
        }
    },
    updated() {
        this.scheduleInferredFieldLabels();
    },
    watch: {
        tableData() {
            this.$nextTick(() => this.syncSelectedRowsFromKeys());
        }
    },
    methods: {
        scheduleInferredFieldLabels() {
            scheduleInferredFieldLabelsFromMComponents(this.$root, this.$slots.default);
        },
        rowKeyResolver(row) {
            if (typeof this.rowKey === 'function') {
                return this.rowKey(row);
            }
            if (typeof this.rowKey === 'string' && this.rowKey !== '') {
                return row ? row[this.rowKey] : undefined;
            }
            const key = resolvePrimaryKey(row);
            return key ? row[key] : undefined;
        },
        onSelectionChange(rows) {
            const keys = rows
                .map((row) => this.rowKeyResolver(row))
                .filter((value) => value !== undefined && value !== null && value !== '');
            this.$root.selectedRows = rows.slice();
            this.$root.selectedRowKeys = keys;
        },
        syncSelectedRowsFromKeys() {
            if (!this.hasSelectionColumn || !this.$refs.table) {
                return;
            }
            const selected = new Set((this.$root.selectedRowKeys || []).map((item) => String(item)));
            this.$refs.table.clearSelection();
            this.tableData.forEach((row) => {
                const key = this.rowKeyResolver(row);
                if (key !== undefined && key !== null && selected.has(String(key))) {
                    this.$refs.table.toggleRowSelection(row, true);
                }
            });
        },
        clearSelection() {
            if (this.$refs.table) {
                this.$refs.table.clearSelection();
            }
            this.$root.selectedRows = [];
            this.$root.selectedRowKeys = [];
        },
        togglePrefsVisible(value) {
            this.prefsVisible = value;
        },
        persistTablePrefs() {
            this.tablePrefsState = normalizeTablePrefs(this.tablePrefsState);
            saveAdminTablePrefs(this.tableKey, this.tablePrefsState);
            this.$root.updateTablePrefs(this.tablePrefsState);
        },
        resetColumnPrefs() {
            this.tablePrefsState = { hidden: [], order: [] };
            this.persistTablePrefs();
        },
        setColumnVisible(column, visible) {
            const hidden = new Set(this.tablePrefsState.hidden);
            if (visible) {
                hidden.delete(column.key);
            } else if (!column.locked) {
                hidden.add(column.key);
            }
            this.tablePrefsState = {
                hidden: Array.from(hidden),
                order: this.tablePrefsState.order.slice()
            };
            this.persistTablePrefs();
        },
        moveColumn(column, offset) {
            const list = this.orderedTableColumns.slice();
            const index = list.findIndex((item) => item.key === column.key);
            const next = clampNumber(index + offset, 0, list.length - 1);
            if (index < 0 || index === next) {
                return;
            }
            const moved = list.splice(index, 1)[0];
            list.splice(next, 0, moved);
            this.tablePrefsState = {
                hidden: this.tablePrefsState.hidden.slice(),
                order: list.map((item) => item.key)
            };
            this.persistTablePrefs();
        },
        createTableVNode(h) {
            return h('el-table', {
                ref: 'table',
                props: {
                    data: this.tableData,
                    border: true,
                    size: 'mini',
                    fit: this.fit,
                    rowKey: this.rowKeyResolver,
                    reserveSelection: this.hasSelectionColumn
                },
                directives: [{ name: 'loading', value: this.tableLoading }],
                on: {
                    'selection-change': this.onSelectionChange
                }
            }, this.visibleTableColumns.map((column) => column.vnode));
        }
    },
    render(h) {
        const children = [];
        if (this.configurable || this.hasSelectionColumn) {
            const toolbarChildren = [];
            if (this.hasSelectionColumn) {
                toolbarChildren.push(h('el-tag', { props: { size: 'mini', type: 'info' } }, [adminT('table.selected_count', {count: this.selectedCount})]));
                toolbarChildren.push(h('el-button', {
                    props: { size: 'mini' },
                    attrs: { icon: 'el-icon-close' },
                    on: { click: this.clearSelection }
                }, [adminT('table.clear_selection')]));
            }
            if (this.configurable) {
                toolbarChildren.push(h('el-button', {
                    props: { size: 'mini' },
                    attrs: { icon: 'el-icon-setting' },
                    on: { click: () => this.togglePrefsVisible(true) }
                }, [adminT('table.column_config')]));
            }
            children.push(h('div', {
                class: 'm-table__toolbar',
                style: {
                    display: 'flex',
                    justifyContent: 'flex-end',
                    gap: '8px',
                    marginBottom: '10px',
                    flexWrap: 'wrap'
                }
            }, toolbarChildren));
        }

        children.push(this.createTableVNode(h));
        if (this.isPagedMode) {
            children.push(h('m-pager'));
        }

        if (this.configurable) {
            children.push(h('el-dialog', {
                props: {
                    title: adminT('table.column_config'),
                    visible: this.prefsVisible,
                    width: '560px',
                    appendToBody: true
                },
                on: {
                    'update:visible': this.togglePrefsVisible
                }
            }, [
                h('div', {
                    style: {
                        display: 'grid',
                        gap: '10px'
                    }
                }, this.orderedTableColumns.map((column, index) => {
                    const hidden = this.tablePrefsState.hidden.includes(column.key);
                    return h('div', {
                        key: column.key,
                        style: {
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'space-between',
                            gap: '12px',
                            padding: '8px 0',
                            borderBottom: '1px solid rgba(148,163,184,0.12)'
                        }
                    }, [
                        h('div', { style: { minWidth: '0', flex: '1' } }, [
                            h('div', { style: { fontWeight: '600' } }, [column.label || column.key]),
                            h('div', { style: { fontSize: '12px', color: '#94a3b8' } }, [column.key])
                        ]),
                        h('div', {
                            style: {
                                display: 'flex',
                                alignItems: 'center',
                                gap: '8px'
                            }
                        }, [
                            h('el-switch', {
                                props: {
                                    value: !hidden,
                                    disabled: column.locked
                                },
                                on: {
                                    input: (value) => this.setColumnVisible(column, value)
                                }
                            }),
                            h('el-button', {
                                props: { size: 'mini', icon: 'el-icon-top' },
                                attrs: { disabled: index === 0 },
                                on: { click: () => this.moveColumn(column, -1) }
                            }),
                            h('el-button', {
                                props: { size: 'mini', icon: 'el-icon-bottom' },
                                attrs: { disabled: index === this.orderedTableColumns.length - 1 },
                                on: { click: () => this.moveColumn(column, 1) }
                            })
                        ])
                    ]);
                })),
                h('span', { slot: 'footer' }, [
                    h('el-button', { on: { click: this.resetColumnPrefs } }, [adminT('table.restore_default')]),
                    h('el-button', { props: { type: 'primary' }, on: { click: () => this.togglePrefsVisible(false) } }, [adminT('table.done')])
                ])
            ]));
        }

        return h('div', { class: 'm-table', style: this.mTableWrapperStyle }, children);
    }
});

Vue.component('m-selector', {
    inject: {
        parentTag: { default: '' }
    },
    props: ['value', 'data', 'disabled', 'valueKey', 'labelKey'],
    template: `
<span>
    <el-select :key="selectRemountKey" :value="value2" size="mini" clearable style="width: 150px" @change="$emit('input', $event)" :disabled="disabled" v-bind="$attrs">
        <el-option v-for="(item, idx) in items" :key="'m-sel-' + idx + '-' + String(item.value)" :label="item.label" :value="item.value"></el-option>
    </el-select>
</span>`,
    computed: {
        /**
         * When options load async (e.g. m-select hydrate), Element UI may not refresh a controlled
         * el-select until remount; key changes 0 → N so the dropdown shows options without DevTools.
         */
        selectRemountKey() {
            const it = this.items;
            const n = it.length;
            if (n === 0) {
                return '0';
            }
            const last = it[n - 1];
            return n + ':' + String(last.value);
        },
        value2() {
            const items = this.items;
            const val = this.value;
            // noinspection EqualityComparisonWithCoercionJS
            const item = items.find((option) => option.value == val);
            if (item) {
                return item.value;
            }
            if (val === undefined) {
                return undefined;
            }
            return val;
        },
        items() {
            const valueKey = this.valueKey || 'value';
            const labelKey = this.labelKey || 'label';
            return normalizeSelectorItemsWithKeys(this.data, valueKey, labelKey);
        }
    }
});

Vue.component('m-navbar', {
    provide() {
        return {
            parentTag: 'm-navbar',
            mode: this.mode,
            ctx: this.pageCtx
        };
    },
    computed: {
        pageCtx() {
            return this.$root.ctx;
        }
    },
    props: ['mode'],
    mounted() {
        this.scheduleInferredFieldLabels();
    },
    updated() {
        this.scheduleInferredFieldLabels();
    },
    methods: {
        scheduleInferredFieldLabels() {
            scheduleInferredFieldLabelsFromMComponents(this.$root, this.$slots.default);
        }
    },
    template: `
<div class="m-navbar" mode=""><slot></slot></div>`
});

Vue.component('m-date', {
    inject: {
        parentTag: { default: '' },
        ctx: { default: null }
    },
    props: {
        prop: String,
        default: {
            default: ''
        }
    },
    created() {
        assertInjectedCtx('m-date', this.ctx);
        registerNavbarRequestField(this, null);
    },
    methods: {
        onRangeChange() {
            this.$nextTick(() => {
                const root = this.$root;
                if (root && typeof root.scheduleRequestReload === 'function') {
                    root.scheduleRequestReload();
                }
            });
        }
    },
    template: `<span><slot></slot><m-date-picker v-model="ctx.request[prop]" @change="onRangeChange"></m-date-picker></span>`
});

Vue.component('m-dialog', {
    provide() {
        return {
            parentTag: 'm-dialog',
            mode: this.mode,
            ctx: this.pageCtx
        };
    },
    props: {
        mode: String,
        inheritKeys: {
            type: String,
            default: null
        }
    },
    computed: {
        pageCtx() {
            return this.$root.ctx;
        }
    },
    created() {
        assertInjectedCtx('m-dialog', this.pageCtx);
    },
    mounted() {
        this.collectAutoFieldsFromSlots();
        this.collectInferredFieldLabelsFromSlots();
    },
    updated() {
        this.scheduleCollectAutoFieldsFromSlots();
        this.collectInferredFieldLabelsFromSlots();
    },
    beforeDestroy() {
        if (this._collectAutoFieldsDebounced && typeof this._collectAutoFieldsDebounced.cancel === 'function') {
            this._collectAutoFieldsDebounced.cancel();
        }
    },
    template: `
<div>
<el-dialog v-if="mode==='create'" :title="adminT('crud.create_title_prefix') + '-' + (pageCtx.createTopic || pageCtx.topic)" :visible.sync="pageCtx.createVisible" class="create-dialog" @open="onCreateOpen" @opened="pageCtx.$refs.create=$refs.create">
    <el-form :model="pageCtx.create" ref="create" size="small">
        <slot></slot>
    </el-form>
    <span slot="footer">
         <el-button type="primary" @click="do_create" size="small">{{pageCtx.createActionText || adminT('crud.create')}}</el-button>
        <el-button @click="pageCtx.createVisible = false; pageCtx.$refs.create.resetFields()" size="small">{{adminT('crud.cancel')}}</el-button>
    </span>
</el-dialog>
<el-dialog v-if="mode==='edit'" :title="adminT('crud.edit') + '-' + pageCtx.topic" :visible.sync="pageCtx.editVisible" class="edit-dialog" @open="onEditOpen" @opened="pageCtx.$refs.edit=$refs.edit">
     <el-form :model="pageCtx.edit" ref="edit" size="small">
        <slot></slot>
    </el-form>
    <span slot="footer">
        <el-button type="primary" @click="do_edit" size="small">{{adminT('crud.save')}}</el-button>
        <el-button @click="pageCtx.editVisible=false" size="small">{{adminT('crud.cancel')}}</el-button>
    </span>
</el-dialog>
<el-dialog v-if="mode==='detail'" :title="adminT('crud.detail')" :visible.sync="pageCtx.detailVisible" class="detail-dialog" @open="focusFirstInput" @opened="pageCtx.$refs.detail=$refs.detail">
    <el-form :model="pageCtx.detail" ref="detail" label-width="150px" size="mini">
        <slot></slot>
    </el-form>
</el-dialog>
</div>`,
    methods: {
        isCurrentDialogOpen() {
            if (this.mode === 'create') {
                return !!this.pageCtx.createVisible;
            }
            if (this.mode === 'edit') {
                return !!this.pageCtx.editVisible;
            }
            return false;
        },
        scheduleCollectAutoFieldsFromSlots() {
            if (this.mode !== 'create' && this.mode !== 'edit') {
                return;
            }
            if (!this.isCurrentDialogOpen()) {
                return;
            }
            if (!this._collectAutoFieldsDebounced) {
                // Keep dynamic v-if form fields reactive while reducing full slot-tree scans on each keystroke.
                this._collectAutoFieldsDebounced = _.debounce(() => this.collectAutoFieldsFromSlots(), 120);
            }
            this._collectAutoFieldsDebounced();
        },
        stabilizeModeModel(mode) {
            const current = this.pageCtx[mode];
            if (!current || typeof current !== 'object') {
                this.pageCtx[mode] = {};
                return;
            }
            // Re-assign model object so dynamically added fields become reactive in Vue 2.
            this.pageCtx[mode] = Object.assign({}, current);
        },
        shouldInheritRequestKey(key) {
            const value = this.inheritKeys;
            if (value === '') {
                return false;
            }
            if (value === null || value === undefined) {
                return !['page', 'size', 'sort', 'order', '_ajax', 'cache'].includes(key);
            }

            const keys = String(value)
                .split(',')
                .map((item) => item.trim())
                .filter((item) => item !== '');
            return keys.includes(key);
        },
        inheritCreateFromRequest() {
            if (this.mode !== 'create') {
                return;
            }
            const request = this.pageCtx.request;
            const create = this.pageCtx.create;
            if (!request || typeof request !== 'object' || !create || typeof create !== 'object') {
                return;
            }

            Object.keys(request).forEach((key) => {
                if (!Object.prototype.hasOwnProperty.call(create, key)) {
                    return;
                }
                if (!this.shouldInheritRequestKey(key)) {
                    return;
                }
                // Keep create form aligned with current filters on every open.
                this.$set(create, key, request[key]);
            });
        },
        collectInferredFieldLabelsFromSlots() {
            scheduleInferredFieldLabelsFromMComponents(this.$root, this.$slots.default);
        },
        collectAutoFieldsFromSlots() {
            if (this.mode !== 'create' && this.mode !== 'edit') {
                return;
            }
            const visit = (nodes) => {
                (nodes || []).forEach((vnode) => {
                    if (!vnode) {
                        return;
                    }
                    const modelExpr = vnode.componentOptions
                    && vnode.componentOptions.model
                    && vnode.componentOptions.model.expression;
                    if (typeof modelExpr === 'string') {
                        registerDialogFormFieldByExpression(this.pageCtx, this.mode, modelExpr, '');
                    }
                    const comp = vnode.componentOptions;
                    const tag = comp && comp.tag ? String(comp.tag) : '';
                    const propsData = comp && comp.propsData ? comp.propsData : {};
                    if (tag.startsWith('m-') && typeof propsData.prop === 'string' && propsData.prop !== '') {
                        const hasDefault = Object.prototype.hasOwnProperty.call(propsData, 'default');
                        const fallback = hasDefault ? propsData.default : '';
                        registerDialogFormFieldByName(this.pageCtx, this.mode, propsData.prop, fallback, hasDefault);
                    }
                    const directives = vnode.data && Array.isArray(vnode.data.directives) ? vnode.data.directives : [];
                    directives.forEach((d) => {
                        if (d && d.name === 'model' && typeof d.expression === 'string') {
                            registerDialogFormFieldByExpression(this.pageCtx, this.mode, d.expression, '');
                        }
                    });
                    if (Array.isArray(vnode.children) && vnode.children.length > 0) {
                        visit(vnode.children);
                    }
                    const child = vnode.componentInstance;
                    if (child && child.$slots) {
                        const slotNames = Object.keys(child.$slots);
                        slotNames.forEach((slotName) => visit(child.$slots[slotName]));
                    }
                });
            };
            visit(this.$slots.default);
        },
        onCreateOpen() {
            this.collectAutoFieldsFromSlots();
            this.stabilizeModeModel('create');
            this.ensureCreateDefaults();
            this.inheritCreateFromRequest();
            this.$emit('open');
            this.focusFirstInput();
        },
        onEditOpen() {
            this.collectAutoFieldsFromSlots();
            this.stabilizeModeModel('edit');
            this.focusFirstInput();
        },
        ensureCreateDefaults() {
            if (this.pageCtx.model && typeof this.pageCtx.model === 'object') {
                return;
            }
            const store = this.pageCtx.__dialogModelFields;
            const inferred = store && store.create && typeof store.create === 'object' ? store.create : null;
            if (!inferred || Object.keys(inferred).length === 0) {
                return;
            }
            if (!this.pageCtx.create || typeof this.pageCtx.create !== 'object') {
                this.pageCtx.create = {};
            }
            Object.keys(inferred).forEach((key) => {
                if (this.pageCtx.create[key] === undefined) {
                    this.$set(this.pageCtx.create, key, inferred[key]);
                }
            });
        },
        submitAndRefresh(action, payload, after) {
            this.ajaxPost(action, payload, () => {
                if (typeof after === 'function') {
                    after();
                }
                this.pageCtx.reload();
            });
        },
        do_create(create) {
            let success = true;

            if (typeof create === 'string') {
                this.pageCtx.$refs[create].validate(valid => success = valid);
            }
            success && this.submitAndRefresh('create', this.pageCtx.create, () => {
                this.pageCtx.createVisible = false;
                this.pageCtx.$refs.create.resetFields();
            });
        },
        do_edit() {
            this.submitAndRefresh('edit', this.pageCtx.edit, () => {
                this.pageCtx.editVisible = false;
            });
        },
        beforeCreate() {
            this.pageCtx.hasDetail = true;
        },
        focusFirstInput() {
            this.$nextTick(() => {
                const firstInput = this.$el.querySelector('input, textarea, [contenteditable]');
                if (firstInput) {
                    firstInput.focus();
                }
            });
        }
    }
});

Vue.component('m-form', {
    props: {
        mode: {
            type: String,
            default: 'edit'
        }
    },
    computed: {
        pageCtx() {
            return this.$root.ctx;
        },
        formModel() {
            if (!this.pageCtx || !this.mode) {
                return {};
            }
            const model = this.pageCtx[this.mode];
            return model && typeof model === 'object' ? model : {};
        }
    },
    provide() {
        return {
            parentTag: 'm-dialog',
            mode: this.mode,
            ctx: this.pageCtx
        };
    },
    created() {
        assertInjectedCtx('m-form', this.pageCtx);
    },
    template: `
<el-form :model="formModel" v-bind="$attrs">
    <slot></slot>
</el-form>`
});

Vue.component('m-text', {
    mixins: [mixinTableColumnCopyable],
    inject: ['parentTag', 'mode', 'ctx'],
    created() {
        assertInjectedCtx('m-text', this.ctx);
        registerLeafFieldDefaults(this, '');
    },
    props: {
        label: String,
        prop: String,
        disabled: Boolean,
        width: [String, Number],
        placeholder: String,
        title: String,
        prefix: String,
        suffix: String,
        default: {
            default: ''
        }
    },
    computed: {
        enableTooltip() {
            return resolveOverflowTooltip(this.$attrs, true);
        },
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        },
        detailTokens() {
            if (this.mode !== 'detail') {
                return [];
            }
            if (!this.shouldRenderDetailTokens()) {
                return [];
            }
            const raw = this.getDetailRawValue();
            if (!raw) {
                return [];
            }
            return String(raw)
                .split(',')
                .map((item) => item.trim())
                .filter((item) => item !== '');
        }
    },
    methods: {
        getDetailRawValue() {
            if (!this.ctx || !this.ctx.detail) {
                return '';
            }
            return getProp(this.ctx.detail, this.prop);
        },
        getDetailDisplayValue() {
            const raw = this.getDetailRawValue();
            const formatter = this.$attrs['formatter'];
            if (typeof formatter === 'function') {
                return formatter(this.ctx.detail || {}, this.prop, raw);
            }
            return raw;
        },
        shouldRenderDetailTokens() {
            const normalized = String(this.prop || '').toLowerCase();
            if (!normalized) {
                return false;
            }
            if (normalized === 'permissions') {
                return true;
            }
            return normalized.endsWith('_permissions');
        }
    },
    template: `
<el-input v-if="parentTag==='m-navbar'" v-model.trim="ctx.request[prop]"
    size="mini" clearable 
    :style="{width: (width||150)+'px'}"
    :placeholder="placeholder||ctx.label[prop]||prop"
    v-bind="$attrs">
</el-input>
<el-table-column v-else-if="parentTag==='m-table'" :prop="prop" :label="resolvedLabel" v-bind="$attrs" :show-overflow-tooltip="enableTooltip" :width="resolveColumnWidth(width, null)" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <slot :row="row">
        <div v-if="copyableEnabled" class="sw-table-cell-with-copy">
            <span :title="title?row[title]:''">{{prefix}}{{$attrs['formatter']?$attrs['formatter'](row, prop, getProp(row,prop)):getProp(row,prop)}}{{suffix}}</span>
            <el-button type="text" class="sw-copy-btn" icon="el-icon-document-copy" @click.stop="copyRowValue(row)" :title="adminT('copy.copy')"></el-button>
        </div>
        <span v-else :title="title?row[title]:''">{{prefix}}{{$attrs['formatter']?$attrs['formatter'](row, prop, getProp(row,prop)):getProp(row,prop)}}{{suffix}}</span>
    </slot>
</el-table-column>
<el-form-item v-else-if="mode==='detail'" :label="resolvedLabel+':'">
    <template v-if="detailTokens.length > 0">
        <div class="sw-detail-token-list">
            <el-tag v-for="token in detailTokens" :key="token" size="mini" effect="plain">{{ token }}</el-tag>
        </div>
    </template>
    <span v-else>{{ getDetailDisplayValue() }}</span>
</el-form-item>
 <el-form-item v-else :label="resolvedLabel+':'" :prop="prop">
     <el-input v-model="ctx[mode][prop]" auto-complete="off" :disabled="disabled" @change="$emit('input', $event)"></el-input>
 </el-form-item>`
});

Vue.component('m-textarea', {
    inject: ['parentTag', 'mode', 'ctx'],
    created() {
        registerLeafFieldDefaults(this, '');
    },
    props: {
        label: String,
        prop: String,
        rows: [String, Number],
        disabled: Boolean,
        default: {
            default: ''
        }
    },
    template: `
<el-form-item :label="(label||ctx.label[prop]||prop)+':'" :prop="prop">
    <el-input v-model="ctx[mode][prop]" auto-complete="off" type="textarea" :rows="rows" :disabled="disabled" @change="$emit('input', $event)"></el-input>
</el-form-item>`
});

Vue.component('m-pre-text', {
    inject: ['mode', 'ctx'],
    props: {
        label: String,
        prop: String,
        lineHeight: {
            type: [String, Number],
            default: 1.7
        }
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        },
        value() {
            if (!this.ctx || !this.ctx.detail) {
                return '';
            }
            return getProp(this.ctx.detail, this.prop);
        },
        preStyle() {
            return {
                whiteSpace: 'pre-wrap',
                lineHeight: String(this.lineHeight)
            };
        }
    },
    template: `
<el-form-item v-if="mode==='detail'" :label="resolvedLabel+':'">
    <div :style="preStyle">{{ value }}</div>
</el-form-item>`
});

Vue.component('m-checkbox', {
    inject: ['parentTag', 'mode', 'ctx'],
    created() {
        registerLeafFieldDefaults(this, '');
    },
    props: {
        label: String,
        prop: String,
        disabled: Boolean,
        default: {
            default: ''
        }
    },
    template: `
<el-form-item :label="(label||ctx.label[prop]||prop)+':'" :prop="prop">
    <el-checkbox v-model="ctx[mode][prop]" :disabled="disabled"><slot></slot></el-checkbox>
</el-form-item>`
});

Vue.component('m-radio', {
    inject: ['parentTag', 'mode', 'ctx'],
    created() {
        registerLeafFieldDefaults(this, '');
    },
    props: {
        label: String,
        prop: String,
        data: [Array, Object, String],
        disabled: Boolean,
        default: {
            default: ''
        }
    },
    computed: {
        radios() {
            if (!this.isString(this.data)) {
                return this.data;
            }

            const fromCtx = this.ctx ? this.ctx[this.data] : undefined;
            if (fromCtx !== undefined) {
                return fromCtx;
            }

            return this.$root ? this.$root[this.data] : undefined;
        }
    },
    template: `
<el-form-item :label="(label||ctx.label[prop]||prop)+':'" :prop="prop">
    <el-radio-group v-model="ctx[mode][prop]" :disabled="disabled">
        <el-radio v-for="(status, id) in radios" :label="id" :key="id">{{status}}</el-radio>
    </el-radio-group>
</el-form-item>`
});

Vue.component('m-select', {
    inject: ['parentTag', 'mode', 'ctx'],
    created() {
        assertInjectedCtx('m-select', this.ctx);
        registerLeafFieldDefaults(this, '');
        this.ensureReactiveOptionsBagOnRoot();
    },
    props: {
        label: String,
        prop: String,
        options: [Array, Object, String],
        /**
         * When set with a string `options` bag name, GET this URL on mount and assign the
         * payload to `$root[options]` if declared, else `$root.bags[options]` (overwrites prior value).
         * Concurrent mounts share one in-flight request; after a successful load, later mounts
         * skip refetch unless `optionsSkipIfLoaded` is false.
         * Prefer a path starting with `/` so `core.js` prepends `BASE_URL` when the app is under a subpath.
         */
        url: String,
        /** If true (default), skip refetch when this bag+URL has already loaded successfully. */
        optionsSkipIfLoaded: {
            type: Boolean,
            default: true
        },
        disabled: Boolean,
        valueKey: String,
        labelKey: String,
        default: {
            default: ''
        }
    },
    mounted() {
        this.scheduleHydrateOptionsBag();
    },
    methods: {
        /**
         * When `options` is a bag name (string) and the page did not declare `$root[bag]`, ensure
         * `$root.bags[bag]` exists (declared on App in bootstrap). Uses `[]` until `url` hydration runs.
         */
        ensureReactiveOptionsBagOnRoot() {
            if (typeof this.options !== 'string') {
                return;
            }
            const root = this.$root;
            const bag = this.options;
            if (root[bag] !== undefined) {
                return;
            }
            if (root.bags[bag] === undefined) {
                this.$set(root.bags, bag, []);
            }
        },
        /**
         * Defer hydration so parent vm mounted hooks (e.g. page script) can run first.
         */
        scheduleHydrateOptionsBag() {
            const delayMs =
                typeof window.AdminUiDefaults === 'object' &&
                window.AdminUiDefaults !== null &&
                typeof window.AdminUiDefaults.optionsHydrateDelayMs === 'number'
                    ? window.AdminUiDefaults.optionsHydrateDelayMs
                    : 50;
            this.$nextTick(() => {
                setTimeout(() => {
                    if (!this._isDestroyed) {
                        this.hydrateOptionsBag();
                    }
                }, delayMs);
            });
        },
        /**
         * Single-flight GET for the named options bag on `$root` (shared list for same `options`
         * string across the page). In-flight / loaded flags live on this `$root` only so each
         * new App instance does not inherit another page's module-scoped cache.
         */
        hydrateOptionsBag() {
            const url = this.url;
            if (!url || typeof this.options !== 'string') {
                return;
            }

            const root = this.$root;
            const bag = this.options;
            const key = bag + '\0' + url;
            const loads = root._bagLoads || (root._bagLoads = Object.create(null));
            const loaded = root._bagsLoaded || (root._bagsLoaded = Object.create(null));

            if (loads[key]) {
                return;
            }
            if (this.optionsSkipIfLoaded && loaded[key]) {
                return;
            }

            loads[key] = true;
            const task = this.ajaxGet(url, (res) => {
                let payload = res;
                if (!Array.isArray(payload) && payload && typeof payload === 'object') {
                    if (Array.isArray(payload.items)) {
                        payload = payload.items;
                    } else if (Array.isArray(payload.list)) {
                        payload = payload.list;
                    }
                }
                if (!Array.isArray(payload)) {
                    payload = [];
                }
                if (root[bag] !== undefined) {
                    this.$set(root, bag, payload);
                } else {
                    this.$set(root.bags, bag, payload);
                }
                loaded[key] = true;
            });
            const done = () => {
                delete loads[key];
            };
            if (task && typeof task.finally === 'function') {
                task.finally(done);
            } else {
                done();
            }
        }
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        },
        resolvedOptions() {
            if (!this.isString(this.options)) {
                return this.options;
            }

            const bag = this.options;
            const root = this.$root;
            if (root && root[bag] !== undefined) {
                return root[bag];
            }
            if (root && root.bags && root.bags[bag] !== undefined) {
                return root.bags[bag];
            }

            const fromCtx = this.ctx ? this.ctx[bag] : undefined;
            if (fromCtx !== undefined) {
                return fromCtx;
            }

            return undefined;
        }
    },
    template: `
<m-selector v-if="parentTag==='m-navbar'" v-model="ctx.request[prop]" v-bind="$attrs" :data="resolvedOptions" :value-key="valueKey" :label-key="labelKey"></m-selector>
<el-form-item v-else :label="resolvedLabel+':'">
    <m-selector v-model="ctx[mode][prop]" v-bind="$attrs" :data="resolvedOptions" :disabled="disabled" :value-key="valueKey" :label-key="labelKey"></m-selector>
</el-form-item>`
});

Vue.component('m-admin', {
    props: {
        label: String,
        prop: String,
        options: String,
        disabled: Boolean,
        includeLocked: {
            type: Boolean,
            default: false
        },
        default: {
            default: ''
        }
    },
    computed: {
        resolvedOptionsBag() {
            if (this.options) {
                return this.options;
            }

            return this.includeLocked ? 'allAdmins' : 'admins';
        },
        resolvedUrl() {
            return this.includeLocked ? '/rbac/admin/options' : '/rbac/admin/options?status=1';
        }
    },
    template: `
<m-select
    :prop="prop"
    :label="label"
    :options="resolvedOptionsBag"
    :url="resolvedUrl"
    :disabled="disabled"
    :default="default"
    value-key="admin_id"
    label-key="admin_name"
    filterable
    clearable
    v-bind="$attrs"
></m-select>`
});

Vue.component('m-roles', {
    inject: ['parentTag', 'mode', 'ctx'],
    created() {
        assertInjectedCtx('m-roles', this.ctx);
        registerLeafFieldDefaults(this, []);
        this.ensureReactiveOptionsBagOnRoot();
    },
    props: {
        label: {
            type: String,
            default: adminT('rbac.role')
        },
        prop: {
            type: String,
            default: 'role_ids'
        },
        options: {
            type: String,
            default: 'roles'
        },
        url: {
            type: String,
            default: ''
        },
        valueKey: {
            type: String,
            default: 'role_id'
        },
        labelKey: {
            type: String,
            default: 'role_name'
        },
        disabled: Boolean,
        default: {
            default() {
                return [];
            }
        }
    },
    mounted() {
        this.hydrateOptionsBag();
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        },
        items() {
            const root = this.$root;
            const bag = this.options;
            if (root && root[bag] !== undefined) {
                return root[bag];
            }
            if (root && root.bags && root.bags[bag] !== undefined) {
                return root.bags[bag];
            }
            return [];
        }
    },
    methods: {
        ensureReactiveOptionsBagOnRoot() {
            const root = this.$root;
            const bag = this.options;
            if (!root || !bag || root[bag] !== undefined) {
                return;
            }
            if (root.bags && root.bags[bag] === undefined) {
                this.$set(root.bags, bag, []);
            }
        },
        hydrateOptionsBag() {
            if (!this.url || !this.options) {
                return;
            }
            hydrateRootOptionsBag(this, this.options, this.url);
        },
        valueOf(item) {
            return item ? item[this.valueKey] : '';
        },
        labelOf(item) {
            return item ? item[this.labelKey] : '';
        }
    },
    template: `
<el-form-item :label="resolvedLabel+':'" :prop="prop">
    <el-checkbox-group v-model="ctx[mode][prop]" class="sw-rbac-role-checkbox-group" :disabled="disabled">
        <el-checkbox v-for="item in items" :label="valueOf(item)" :key="valueOf(item)">
            {{ labelOf(item) }}
        </el-checkbox>
    </el-checkbox-group>
</el-form-item>`
});

Vue.component('m-role', {
    props: {
        label: {
            type: String,
            default: adminT('rbac.role')
        },
        prop: {
            type: String,
            default: 'role_id'
        },
        options: {
            type: String,
            default: 'roles'
        },
        url: {
            type: String,
            default: '/rbac/admin-role/roles'
        },
        width: [String, Number],
        fallbackPrefix: {
            type: String,
            default: '#'
        }
    },
    template: `
<m-ref-text
    :prop="prop"
    :label="label"
    :options="options"
    :url="url"
    value-key="role_id"
    label-key="role_name"
    :width="width"
    :fallback-prefix="fallbackPrefix"
    v-bind="$attrs"
></m-ref-text>`
});

Vue.component('md-select', {
    inject: ['parentTag', 'mode', 'ctx'],
    created() {
        assertInjectedCtx('md-select', this.ctx);
        ensureRootOptionsBag(this, this.dictBag);
    },
    mounted() {
        hydrateRootOptionsBag(this, this.dictBag, this.dictUrl);
    },
    props: {
        label: String,
        prop: String,
        dict: String,
        disabled: Boolean,
        valueType: {
            type: String,
            default: 'auto'
        },
        default: {
            default: ''
        }
    },
    computed: {
        dictBag() {
            return dictOptionsBagName(this.dict);
        },
        dictUrl() {
            return dictOptionsUrl(this.dict);
        },
        resolvedOptions() {
            return normalizeDictItems(readRootOptionsBag(this, this.dictBag))
                .map((item) => ({value: this.selectValue(item.value), label: item.label}));
        },
        resolvedDefault() {
            if (this.default === '' || this.default === undefined || this.default === null) {
                return this.default;
            }
            return this.selectValue(this.default);
        }
    },
    methods: {
        selectValue(value) {
            if (this.valueType === 'string') {
                return String(value);
            }
            if (this.valueType === 'int') {
                return Number(value);
            }
            if (typeof value === 'string' && /^-?\d+$/.test(value)) {
                return Number(value);
            }
            return value;
        }
    },
    template: `
<m-select
    :prop="prop"
    :label="label"
    :options="resolvedOptions"
    :disabled="disabled"
    :default="resolvedDefault"
    v-bind="$attrs"
></m-select>`
});

Vue.component('md-radio', {
    inject: ['parentTag', 'mode', 'ctx'],
    created() {
        assertInjectedCtx('md-radio', this.ctx);
        registerLeafFieldDefaults(this, '');
        ensureRootOptionsBag(this, this.dictBag);
    },
    mounted() {
        hydrateRootOptionsBag(this, this.dictBag, this.dictUrl);
    },
    props: {
        label: String,
        prop: String,
        dict: String,
        disabled: Boolean,
        default: {
            default: ''
        },
        valueType: {
            type: String,
            default: 'auto'
        }
    },
    computed: {
        dictBag() {
            return dictOptionsBagName(this.dict);
        },
        dictUrl() {
            return dictOptionsUrl(this.dict);
        },
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        },
        items() {
            return normalizeDictItems(readRootOptionsBag(this, this.dictBag));
        }
    },
    methods: {
        radioValue(value) {
            if (this.valueType === 'string') {
                return String(value);
            }
            if (this.valueType === 'int') {
                return Number(value);
            }
            if (typeof value === 'string' && /^-?\d+$/.test(value)) {
                return Number(value);
            }
            return value;
        }
    },
    template: `
<el-form-item :label="resolvedLabel+':'" :prop="prop">
    <el-radio-group v-model="ctx[mode][prop]" :disabled="disabled">
        <el-radio v-for="item in items" :label="radioValue(item.value)" :key="String(item.value)">{{item.label}}</el-radio>
    </el-radio-group>
</el-form-item>`
});

Vue.component('m-remote-select', {
    inject: ['parentTag', 'mode', 'ctx'],
    created() {
        assertInjectedCtx('m-remote-select', this.ctx);
        registerLeafFieldDefaults(this, '');
        this.debouncedFetch = _.debounce((keyword) => this.fetchOptions(keyword), this.debounceMs);
    },
    props: {
        label: String,
        prop: String,
        endpoint: String,
        data: [String, Array, Object],
        disabled: Boolean,
        default: {
            default: ''
        },
        valueKey: {
            type: String,
            default: 'value'
        },
        labelKey: {
            type: String,
            default: 'label'
        },
        queryKey: {
            type: String,
            default: 'q'
        },
        minChars: {
            type: Number,
            default: 0
        },
        debounceMs: {
            type: Number,
            default: 300
        },
        clearable: {
            type: Boolean,
            default: true
        }
    },
    data() {
        return {
            options: [],
            loading: false,
            requestSeq: 0
        };
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        },
        sourceData() {
            return this.isString(this.data) ? this.ctx[this.data] : this.data;
        }
    },
    methods: {
        onRemoteSearch(keyword) {
            if (!this.endpoint) {
                this.options = normalizeRemoteItems(this.sourceData, this.valueKey, this.labelKey);
                return;
            }
            const text = (keyword || '').trim();
            if (text.length < this.minChars) {
                this.options = [];
                return;
            }
            this.debouncedFetch(text);
        },
        onDropdownVisible(visible) {
            if (!visible) {
                return;
            }
            if (!this.endpoint) {
                this.options = normalizeRemoteItems(this.sourceData, this.valueKey, this.labelKey);
                return;
            }
            if (this.options.length === 0) {
                this.fetchOptions('');
            }
        },
        fetchOptions(keyword) {
            if (!this.endpoint) {
                return;
            }

            const seq = ++this.requestSeq;
            this.loading = true;
            const request = {};
            request[this.queryKey] = keyword;

            const task = this.ajaxGet(this.endpoint, request, (res) => {
                if (seq !== this.requestSeq) {
                    return;
                }
                this.options = normalizeRemoteItems(res, this.valueKey, this.labelKey);
            });

            if (!task || typeof task.finally !== 'function') {
                if (seq === this.requestSeq) {
                    this.loading = false;
                }
                return;
            }

            task.finally(() => {
                if (seq === this.requestSeq) {
                    this.loading = false;
                }
            });
        }
    },
    beforeDestroy() {
        // Cancel pending debounced requests to avoid stale callback updates after component disposal.
        if (this.debouncedFetch && typeof this.debouncedFetch.cancel === 'function') {
            this.debouncedFetch.cancel();
        }
    },
    template: `
<el-select v-if="parentTag==='m-navbar'" v-model="ctx.request[prop]" filterable remote reserve-keyword size="mini" :remote-method="onRemoteSearch" :loading="loading" :clearable="clearable" :disabled="disabled" :style="{width: ($attrs.width||180)+'px'}" @visible-change="onDropdownVisible" v-bind="$attrs">
    <el-option v-for="item in options" :key="item.value" :label="item.label" :value="item.value"></el-option>
</el-select>
<el-form-item v-else :label="resolvedLabel+':'">
    <el-select v-model="ctx[mode][prop]" filterable remote reserve-keyword :remote-method="onRemoteSearch" :loading="loading" :clearable="clearable" :disabled="disabled" style="width: 100%" @visible-change="onDropdownVisible" v-bind="$attrs">
        <el-option v-for="item in options" :key="item.value" :label="item.label" :value="item.value"></el-option>
    </el-select>
</el-form-item>`
});

Vue.component('m-switch', {
    inject: ['parentTag', 'mode', 'ctx'],
    created() {
        registerLeafFieldDefaults(this, '');
    },
    props: {
        label: String,
        prop: String,
        disabled: Boolean,
        default: {
            default: ''
        }
    },
    template: `
<el-form-item :label="(label||ctx.label[prop]||prop)+':'">
    <el-switch v-model="ctx[mode][prop]" :disabled="disabled"></el-switch>
</el-form-item>`
});

/**
 * Table cell label for Unix-timestamp fields: today → `HH:mm:ss`, else `MM-DD HH:mm`.
 * @param {Function} momentFn `this.$moment`
 * @param {number|string} value Unix seconds
 * @param {boolean} smart
 * @param {string} fixedFormat Used when `smart` is false (moment pattern).
 * @returns {string}
 */
function formatAdminTableTimestampCell(momentFn, value, smart, fixedFormat) {
    if (!value) {
        return '';
    }
    const m = momentFn(value * 1000);
    if (!smart) {
        return m.format(fixedFormat);
    }
    const today = momentFn().startOf('day');
    if (m.clone().startOf('day').isSame(today, 'day')) {
        return m.format('HH:mm:ss');
    }
    return m.format('MM-DD HH:mm');
}

Vue.component('m-timestamp', {
    inject: ['parentTag', 'ctx'],
    props: {
        label: String,
        prop: String,
        disabled: Boolean,
        width: [String, Number],
        /** When false, table cell uses `format` only (no today / other-day split). */
        smartDisplay: {
            type: Boolean,
            default: true
        },
        /** Table cell when `smart-display` is false. */
        format: {
            type: String,
            default: 'YYYY-MM-DD HH:mm:ss'
        },
        /** Tooltip + detail only; default fixed full datetime `YYYY-MM-DD HH:mm:ss` (override if needed). */
        fullFormat: {
            type: String,
            default: 'YYYY-MM-DD HH:mm:ss'
        },
        /** When true, column uses `min-width` only (grows with table `fit` + spare width). */
        flexColumn: {
            type: Boolean,
            default: false
        }
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        },
        tsColWidth() {
            if (this.flexColumn) {
                return undefined;
            }
            return resolveAdminTimestampColumnWidth(this.width, this.smartDisplay);
        },
        tsColMinWidth() {
            if (!this.flexColumn) {
                return undefined;
            }
            return resolveAdminTimestampColumnWidth(this.width, this.smartDisplay);
        }
    },
    template: `
<el-table-column v-if="parentTag==='m-table'" :prop="prop" :label="resolvedLabel" :width="tsColWidth" :min-width="tsColMinWidth" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <template v-if="getProp(row, prop)">
        <el-tooltip effect="dark" placement="top" :content="formatFull(getProp(row, prop))">
            <span class="sw-ts-cell">{{ formatCell(getProp(row, prop)) }}</span>
        </el-tooltip>
    </template>
    <span v-else></span>
</el-table-column>
<el-form-item v-else :label="resolvedLabel+':'">{{ ctx.detail[prop]|date(fullFormat)}}</el-form-item>`,
    methods: {
        formatCell(value) {
            return formatAdminTableTimestampCell(this.$moment, value, this.smartDisplay, this.format);
        },
        formatFull(value) {
            if (!value) {
                return '';
            }
            return this.$moment(value * 1000).format(this.fullFormat);
        }
    }
});

Vue.component('m-time', {
    inject: ['parentTag', 'ctx'],
    props: {
        label: String,
        prop: String,
        disabled: Boolean,
        width: [String, Number],
        smartDisplay: {
            type: Boolean,
            default: true
        },
        format: {
            type: String,
            default: 'YYYY-MM-DD HH:mm:ss'
        },
        /** Same as `m-timestamp` — tooltip + detail; default `YYYY-MM-DD HH:mm:ss`. */
        fullFormat: {
            type: String,
            default: 'YYYY-MM-DD HH:mm:ss'
        },
        flexColumn: {
            type: Boolean,
            default: false
        }
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        },
        tsColWidth() {
            if (this.flexColumn) {
                return undefined;
            }
            return resolveAdminTimestampColumnWidth(this.width, this.smartDisplay);
        },
        tsColMinWidth() {
            if (!this.flexColumn) {
                return undefined;
            }
            return resolveAdminTimestampColumnWidth(this.width, this.smartDisplay);
        }
    },
    template: `
<el-table-column v-if="parentTag==='m-table'" :prop="prop" :label="resolvedLabel" :width="tsColWidth" :min-width="tsColMinWidth" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <template v-if="getProp(row, prop)">
        <el-tooltip effect="dark" placement="top" :content="formatFull(getProp(row, prop))">
            <span class="sw-ts-cell">{{ formatCell(getProp(row, prop)) }}</span>
        </el-tooltip>
    </template>
    <span v-else></span>
</el-table-column>
<el-form-item v-else :label="resolvedLabel+':'">{{ ctx.detail[prop]|date(fullFormat)}}</el-form-item>`,
    methods: {
        formatCell(value) {
            return formatAdminTableTimestampCell(this.$moment, value, this.smartDisplay, this.format);
        },
        formatFull(value) {
            if (!value) {
                return '';
            }
            return this.$moment(value * 1000).format(this.fullFormat);
        }
    }
});

Vue.component('m-json', {
    inject: ['ctx'],
    props: ['label', 'prop', 'disabled'],
    template: `<el-form-item :label="(label||ctx.label[prop]||prop)+':'"><pre>{{ ctx.detail[prop]|json}}</pre></el-form-item>`
});

/** Max chars for m-index row JSON tooltip preview (full row still available via copy). */
var ADMIN_INDEX_ROW_TOOLTIP_JSON_MAX = 24000;

Vue.component('m-selection', {
    inject: ['parentTag'],
    props: {
        width: [String, Number],
        selectable: {
            type: Function,
            default: null
        }
    },
    template: `
<el-table-column
    v-if="parentTag==='m-table'"
    type="selection"
    column-key="selection"
    :width="resolveColumnWidth(width, 52)"
    :selectable="selectable || undefined"
></el-table-column>`
});

Vue.component('m-index', {
    inject: ['parentTag'],
    props: {
        width: [String, Number],
        /** Same as Element `el-table-column type="index"` `index`: start offset (number) or `($index) => displayIndex`. */
        index: [Number, Function]
    },
    methods: {
        copyRowAsJson(row) {
            copyRowJsonWithMessage(this, row);
        },
        displayIndex($index) {
            let i = $index + 1;
            const index = this.index;
            if (typeof index === 'number') {
                i = $index + index;
            } else if (typeof index === 'function') {
                i = index($index);
            }
            return i;
        },
        /** Formatted JSON for hover tooltip; truncated with hint when huge. */
        rowJsonTooltipText(row) {
            try {
                const s = JSON.stringify(row, null, 2);
                const max = ADMIN_INDEX_ROW_TOOLTIP_JSON_MAX;
                if (s.length > max) {
                    return `${s.slice(0, max)}\n${adminT('table.truncated_hint')}`;
                }
                return s;
            } catch (e) {
                const msg = e && e.message ? e.message : String(e);
                return adminT('copy.serialize_failed_with_reason', {reason: msg});
            }
        }
    },
    template: `
<el-table-column v-if="parentTag==='m-table'" label="#" :width="resolveColumnWidth(width, 64)" v-slot="{row, $index}">
    <template slot="header">
        <span title="序号">#</span>
    </template>
    <el-tooltip effect="dark" placement="left-start" popper-class="sw-row-json-tooltip" :enterable="true">
        <pre slot="content" class="sw-row-json-tooltip__pre">{{ rowJsonTooltipText(row) }}</pre>
        <div class="sw-table-cell-with-copy sw-index-cell">
            <span>{{ displayIndex($index) }}</span>
            <el-button type="text" class="sw-copy-btn" icon="el-icon-document-copy" @click.stop="copyRowAsJson(row)" :title="adminT('copy.copy_row_json')"></el-button>
        </div>
    </el-tooltip>
</el-table-column>`
});

const mIdAccountColumn = {
    mixins: [mixinTableColumnCopyable],
    inject: ['parentTag', 'ctx'],
    props: {
        label: String,
        prop: String
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        }
    },
    template: `
<el-table-column :prop="prop" :label="resolvedLabel" width="100" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <div v-if="copyableEnabled" class="sw-table-cell-with-copy">
        <span>{{row[prop]}}</span>
        <el-button type="text" class="sw-copy-btn" icon="el-icon-document-copy" @click.stop="copyRowValue(row)" :title="adminT('copy.copy')"></el-button>
    </div>
    <span v-else>{{row[prop]}}</span>
</el-table-column>`
};
Vue.component('m-id', mIdAccountColumn);
Vue.component('m-account', mIdAccountColumn);

Vue.component('m-email', {
    mixins: [mixinTableColumnCopyable],
    props: {
        label: String,
        prop: String
    },
    inject: ['parentTag', 'ctx'],
    computed: {
        resolvedProp() {
            return this.prop || 'email';
        },
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.resolvedProp, adminT('auth.email'));
        }
    },
    methods: {
        resolveCopyColumnProp() {
            return this.resolvedProp;
        }
    },
    template: `
<el-table-column :prop="resolvedProp" :label="resolvedLabel" :width="resolveColumnWidth($attrs.width, 250)" :show-overflow-tooltip="resolveOverflowTooltip($attrs, true)" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(resolvedProp)">{{ resolvedLabel }}</span>
    </template>
    <div v-if="copyableEnabled" class="sw-table-cell-with-copy">
        <span>{{row[resolvedProp]}}</span>
        <el-button type="text" class="sw-copy-btn" icon="el-icon-document-copy" @click.stop="copyRowValue(row)" :title="adminT('copy.copy')"></el-button>
    </div>
    <span v-else>{{row[resolvedProp]}}</span>
</el-table-column>`
});

Vue.component('m-ref-text', {
    inject: ['parentTag', 'ctx'],
    created() {
        assertInjectedCtx('m-ref-text', this.ctx);
        this.ensureReactiveOptionsBagOnRoot();
    },
    mounted() {
        this.hydrateOptionsBag();
    },
    props: {
        label: String,
        prop: String,
        options: {
            type: String,
            required: true
        },
        url: String,
        valueKey: {
            type: String,
            default: 'value'
        },
        labelKey: {
            type: String,
            default: 'label'
        },
        width: [String, Number],
        fallbackPrefix: {
            type: String,
            default: '#'
        }
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        },
        items() {
            return readRootOptionsBag(this, this.options);
        },
        labelMap() {
            const map = Object.create(null);
            (this.items || []).forEach((item) => {
                if (!item || typeof item !== 'object') {
                    return;
                }
                map[item[this.valueKey]] = item[this.labelKey];
            });
            return map;
        }
    },
    methods: {
        ensureReactiveOptionsBagOnRoot() {
            ensureRootOptionsBag(this, this.options);
        },
        hydrateOptionsBag() {
            hydrateRootOptionsBag(this, this.options, this.url);
        },
        displayValue(row) {
            const value = getProp(row, this.prop);
            if (value === undefined || value === null || value === '') {
                return '-';
            }
            if (this.labelMap[value] !== undefined && this.labelMap[value] !== null && this.labelMap[value] !== '') {
                return this.labelMap[value];
            }
            return this.fallbackPrefix + value;
        }
    },
    template: `
<el-table-column
    v-if="parentTag==='m-table'"
    :prop="prop"
    :label="resolvedLabel"
    :width="resolveColumnWidth(width, null)"
    v-bind="$attrs"
    v-slot="{row}"
>
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <span>{{ displayValue(row) }}</span>
</el-table-column>
<el-form-item v-else-if="parentTag==='m-dialog'" :label="resolvedLabel+':'">
    <span>{{ displayValue(ctx.detail || {}) }}</span>
</el-form-item>`
});

Vue.component('m-ip', {
    mixins: [mixinTableColumnCopyable],
    inject: ['parentTag', 'ctx'],
    props: {
        label: String,
        prop: String
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        }
    },
    template: `
<el-table-column :prop="prop" :label="resolvedLabel" width="120" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <div v-if="copyableEnabled" class="sw-table-cell-with-copy">
        <span v-if="isLocalIp(row[prop])">{{row[prop]}}</span>
        <a v-else target="_blank" class="el-link" :href="'https://www.baidu.com/s?wd='+row[prop]" type="primary">{{row[prop]}}</a>
        <el-button type="text" class="sw-copy-btn" icon="el-icon-document-copy" @click.stop="copyRowValue(row)" :title="adminT('copy.copy')"></el-button>
    </div>
    <span v-else-if="isLocalIp(row[prop])">{{row[prop]}}</span>
    <a v-else target="_blank" class="el-link" :href="'https://www.baidu.com/s?wd='+row[prop]" type="primary">{{row[prop]}}</a>
</el-table-column>`,
    methods: {
        isLocalIp(value) {
            if (typeof value !== 'string') {
                return false;
            }
            return value === '127.0.0.1' || value === '::1' || value.startsWith('192.168.');
        }
    }
});

Vue.component('m-number', {
    inject: ['parentTag', 'ctx'],
    props: {
        label: String,
        prop: String,
        digits: {
            type: Number,
            default: 2
        },
        thousand: {
            type: Boolean,
            default: false
        },
        width: [String, Number],
        prefix: {
            type: String,
            default: ''
        },
        suffix: {
            type: String,
            default: ''
        }
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        }
    },
    methods: {
        renderValue(value) {
            const formatted = formatNumberValue(value, this.digits, this.thousand);
            if (formatted === '') {
                return '';
            }
            return this.prefix + formatted + this.suffix;
        }
    },
    template: `
<el-table-column v-if="parentTag==='m-table'" :prop="prop" :label="resolvedLabel" :width="resolveColumnWidth(width, 120)" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <span style="display:inline-block;width:100%;text-align:right;">{{renderValue(getProp(row, prop))}}</span>
</el-table-column>
	<el-form-item v-else :label="resolvedLabel+':'">{{renderValue(ctx.detail[prop])}}</el-form-item>`
});

Vue.component('m-file-size', {
    inject: ['parentTag', 'ctx'],
    props: {
        label: String,
        prop: String,
        width: [String, Number]
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        }
    },
    methods: {
        renderValue(value) {
            return formatFileSizeValue(value);
        }
    },
    template: `
<el-table-column v-if="parentTag==='m-table'" :prop="prop" :label="resolvedLabel" :width="resolveColumnWidth(width, 120)" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <span style="display:inline-block;width:100%;text-align:right;">{{renderValue(getProp(row, prop))}}</span>
</el-table-column>
<el-form-item v-else :label="resolvedLabel+':'">{{renderValue(ctx.detail[prop])}}</el-form-item>`
});

Vue.component('m-bool', {
    inject: ['parentTag', 'ctx'],
    props: {
        label: String,
        prop: String,
        width: [String, Number],
        trueText: {
            type: String,
            default: adminT('common.yes')
        },
        falseText: {
            type: String,
            default: adminT('common.no')
        }
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        }
    },
    methods: {
        textOf(value) {
            return Number(value) === 1 || value === true ? this.trueText : this.falseText;
        }
    },
    template: `
<el-table-column v-if="parentTag==='m-table'" :prop="prop" :label="resolvedLabel" :width="resolveColumnWidth(width, 90)" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <span>{{textOf(getProp(row, prop))}}</span>
</el-table-column>
<el-form-item v-else :label="resolvedLabel+':'">{{textOf(ctx.detail[prop])}}</el-form-item>`
});

Vue.component('m-present-text', {
    inject: ['parentTag', 'ctx'],
    props: {
        label: String,
        prop: String,
        width: [String, Number],
        presentText: {
            type: String,
            default: adminT('common.yes')
        },
        emptyText: {
            type: String,
            default: adminT('common.no')
        }
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        }
    },
    methods: {
        isPresent(row) {
            const value = getProp(row, this.prop);
            if (typeof value === 'number') {
                return value > 0;
            }
            if (typeof value === 'string' && value !== '') {
                const n = Number(value);
                if (!Number.isNaN(n)) {
                    return n > 0;
                }
                return value.trim() !== '';
            }
            return !!value;
        },
        textOf(row) {
            return this.isPresent(row) ? this.presentText : this.emptyText;
        }
    },
    template: `
<el-table-column v-if="parentTag==='m-table'" :prop="prop" :label="resolvedLabel" :width="resolveColumnWidth(width, 90)" v-bind="$attrs" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <span>{{ textOf(row) }}</span>
</el-table-column>
<el-form-item v-else :label="resolvedLabel+':'">{{ textOf(ctx.detail || {}) }}</el-form-item>`
});

Vue.component('m-tag', {
    inject: ['parentTag', 'ctx'],
    props: {
        label: String,
        prop: String,
        options: [Array, Object, String],
        valueKey: {
            type: String,
            default: 'value'
        },
        labelKey: {
            type: String,
            default: 'label'
        },
        typeKey: {
            type: String,
            default: 'type'
        },
        width: [String, Number]
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        },
        statusItems() {
            const source = this.isString(this.options) ? this.ctx[this.options] : this.options;
            return normalizeStatusItems(source, this.valueKey, this.labelKey, this.typeKey);
        }
    },
    methods: {
        matchStatus(value) {
            const target = String(value);
            const found = this.statusItems.find((item) => String(item.value) === target);
            return found || {value, label: value === null || value === undefined ? '' : String(value), type: ''};
        }
    },
    template: `
<el-table-column v-if="parentTag==='m-table'" :prop="prop" :label="resolvedLabel" :width="resolveColumnWidth(width, 120)" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <template v-for="st in [matchStatus(getProp(row, prop))]">
        <el-tag :key="String(st.value) + '-' + st.label" size="mini" :type="st.type">{{ st.label }}</el-tag>
    </template>
</el-table-column>
<el-form-item v-else :label="resolvedLabel+':'">
    <template v-for="st in [matchStatus(ctx.detail[prop])]">
        <el-tag :key="String(st.value) + '-' + st.label" size="mini" :type="st.type">{{ st.label }}</el-tag>
    </template>
</el-form-item>`
});

Vue.component('m-amount', {
    inject: ['parentTag', 'ctx'],
    props: {
        label: String,
        prop: String,
        digits: {
            type: Number,
            default: 2
        },
        thousand: {
            type: Boolean,
            default: true
        },
        symbol: {
            type: String,
            default: '¥'
        },
        width: [String, Number]
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        }
    },
    methods: {
        renderValue(value) {
            const formatted = formatNumberValue(value, this.digits, this.thousand);
            return formatted === '' ? '' : this.symbol + formatted;
        },
        valueColor(value) {
            const num = Number(value);
            if (!Number.isFinite(num)) {
                return '';
            }
            return num < 0 ? '#f56c6c' : '';
        }
    },
    template: `
<el-table-column v-if="parentTag==='m-table'" :prop="prop" :label="resolvedLabel" :width="resolveColumnWidth(width, 140)" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <span :style="{display:'inline-block',width:'100%',textAlign:'right',color:valueColor(getProp(row, prop))}">{{renderValue(getProp(row, prop))}}</span>
</el-table-column>
<el-form-item v-else :label="resolvedLabel+':'"><span :style="{color:valueColor(ctx.detail[prop])}">{{renderValue(ctx.detail[prop])}}</span></el-form-item>`
});

Vue.component('m-ellipsis', {
    inject: ['parentTag', 'ctx'],
    props: {
        label: String,
        prop: String,
        width: [String, Number],
        max: {
            type: Number,
            default: 24
        }
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        }
    },
    template: `
<el-table-column v-if="parentTag==='m-table'" :prop="prop" :label="resolvedLabel" :width="resolveColumnWidth(width, 180)" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <template v-for="cell in [ellipsisCellForRow(row)]">
        <el-tooltip :key="cell.vueKey" effect="dark" :content="cell.full" placement="top" :disabled="cell.tooltipDisabled">
            <span>{{ cell.short }}</span>
        </el-tooltip>
    </template>
</el-table-column>
<el-form-item v-else :label="resolvedLabel+':'">{{ ellipsisDetailText() }}</el-form-item>`,
    methods: {
        textOf(value) {
            if (value === null || value === undefined) {
                return '';
            }
            return String(value);
        },
        ellipsisCellForRow(row) {
            const full = this.textOf(getProp(row, this.prop));
            const max = clampNumber(this.max, 4, 200);
            const raw = getProp(row, this.prop);
            const rowKey = raw !== undefined && raw !== null ? String(raw) : full.slice(0, 16);
            return {
                full,
                short: full.length > max ? full.slice(0, max) + '...' : full,
                tooltipDisabled: full.length <= max,
                vueKey: this.prop + '-' + rowKey
            };
        },
        ellipsisDetailText() {
            return this.textOf(this.ctx.detail[this.prop]);
        },
        clampNumber
    }
});

Vue.component('m-image', {
    inject: ['parentTag', 'ctx'],
    props: {
        label: String,
        prop: String,
        width: [String, Number],
        height: [String, Number]
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        },
        resolvedWidth() {
            return this.width || 64;
        },
        resolvedHeight() {
            return this.height || 64;
        }
    },
    methods: {
        srcOf(value) {
            return value ? String(value) : '';
        },
        imageRowPack(row) {
            const raw = getProp(row, this.prop);
            const src = this.srcOf(raw);
            const rowKey = raw !== undefined && raw !== null ? String(raw) : '_';
            return {src, rowKey};
        },
        imageDetailPack() {
            const raw = this.ctx.detail[this.prop];
            return {src: this.srcOf(raw)};
        }
    },
    template: `
<el-table-column v-if="parentTag==='m-table'" :prop="prop" :label="resolvedLabel" :width="resolveColumnWidth(width, 100)" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <template v-for="p in [imageRowPack(row)]">
        <el-image v-if="p.src" :key="'mimg-' + prop + '-' + p.rowKey" :src="p.src" :preview-src-list="[p.src]" :style="{width: resolvedWidth + 'px', height: resolvedHeight + 'px'}" fit="cover"></el-image>
        <span v-else :key="'mimg-empty-' + prop + '-' + p.rowKey">-</span>
    </template>
</el-table-column>
<el-form-item v-else :label="resolvedLabel+':'">
    <template v-for="p in [imageDetailPack()]">
        <el-image v-if="p.src" :key="'mdtl-' + prop + '-' + p.src" :src="p.src" :preview-src-list="[p.src]" :style="{width: (resolvedWidth * 2) + 'px', height: (resolvedHeight * 2) + 'px'}" fit="cover"></el-image>
        <span v-else :key="'mdtl-empty-' + prop">-</span>
    </template>
</el-form-item>`
});

Vue.component('m-file-preview', {
    inject: ['parentTag', 'ctx'],
    props: {
        label: String,
        prop: String,
        width: [String, Number],
        nameProp: {
            type: String,
            default: ''
        },
        mimeProp: {
            type: String,
            default: 'mime_type'
        }
    },
    data() {
        return {
            previewVisible: false
        };
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop, adminT('table.preview'));
        }
    },
    methods: {
        fileUrl(row) {
            return String(this.getProp(row, this.prop) || '');
        },
        fileName(row) {
            if (this.nameProp) {
                return String(this.getProp(row, this.nameProp) || '');
            }
            return this.fileUrl(row);
        },
        mimeType(row) {
            return String(this.getProp(row, this.mimeProp) || '').toLowerCase();
        },
        isImage(row) {
            const mime = this.mimeType(row);
            if (mime.startsWith('image/')) {
                return true;
            }
            return /\.(png|jpe?g|gif|webp|svg|bmp|ico)(\?.*)?$/.test(this.fileUrl(row).toLowerCase());
        },
        isPdf(row) {
            const mime = this.mimeType(row);
            if (mime === 'application/pdf') {
                return true;
            }
            return /\.pdf(\?.*)?$/.test(this.fileUrl(row).toLowerCase());
        },
        isOffice(row) {
            const url = this.fileUrl(row).toLowerCase();
            const mime = this.mimeType(row);
            return mime.includes('officedocument') ||
                mime.includes('msword') ||
                mime.includes('excel') ||
                mime.includes('powerpoint') ||
                /\.(docx?|xlsx?|pptx?)(\?.*)?$/.test(url);
        },
        linkText(row) {
            if (this.isPdf(row)) {
                return adminT('table.preview_pdf');
            }
            if (this.isOffice(row)) {
                return adminT('table.open_attachment');
            }
            const name = this.fileName(row);
            return name ? adminT('table.view_attachment') : adminT('table.open_attachment');
        },
        openPreview() {
            this.previewVisible = true;
        }
    },
    template: `
<el-table-column v-if="parentTag==='m-table'" :prop="prop" :label="resolvedLabel" :width="resolveColumnWidth(width, 110)" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <el-image
        v-if="isImage(row) && fileUrl(row)"
        :src="fileUrl(row)"
        :preview-src-list="[fileUrl(row)]"
        style="width:48px;height:48px;border-radius:4px;"
        fit="cover"
    ></el-image>
    <a v-else-if="fileUrl(row)" :href="fileUrl(row)" target="_blank" rel="noopener noreferrer">{{ linkText(row) }}</a>
    <span v-else>-</span>
</el-table-column>
<el-form-item v-else :label="resolvedLabel+':'">
    <div v-if="fileUrl(ctx.detail)">
        <el-image
            v-if="isImage(ctx.detail)"
            :src="fileUrl(ctx.detail)"
            :preview-src-list="[fileUrl(ctx.detail)]"
            style="width:96px;height:96px;border-radius:6px;"
            fit="cover"
        ></el-image>
        <iframe
            v-else-if="isPdf(ctx.detail)"
            :src="fileUrl(ctx.detail)"
            style="width:100%;height:360px;border:0;border-radius:6px;background:#f8fafc;"
        ></iframe>
        <div v-else style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <el-button size="mini" type="primary" plain @click="openPreview">{{adminT('table.preview')}}</el-button>
            <el-link :href="fileUrl(ctx.detail)" target="_blank" type="primary">{{ fileName(ctx.detail) || linkText(ctx.detail) }}</el-link>
        </div>
    </div>
    <span v-else>-</span>

    <el-dialog :title="adminT('table.attachment_preview')" :visible.sync="previewVisible" width="960px" append-to-body>
        <div v-if="fileUrl(ctx.detail)">
            <el-image
                v-if="isImage(ctx.detail)"
                :src="fileUrl(ctx.detail)"
                :preview-src-list="[fileUrl(ctx.detail)]"
                style="max-width:100%;max-height:520px;border-radius:6px;"
                fit="contain"
            ></el-image>
            <iframe
                v-else-if="isPdf(ctx.detail)"
                :src="fileUrl(ctx.detail)"
                style="width:100%;height:560px;border:0;border-radius:6px;background:#f8fafc;"
            ></iframe>
            <div v-else style="display:grid;gap:12px;">
                <div style="color:#64748b;">{{adminT('table.attachment_preview_unsupported')}}</div>
                <el-link :href="fileUrl(ctx.detail)" target="_blank" type="primary">{{ fileName(ctx.detail) || adminT('table.open_attachment') }}</el-link>
            </div>
        </div>
        <span v-else>-</span>
    </el-dialog>
</el-form-item>`
});

Vue.component('m-link-external', {
    inject: ['parentTag', 'ctx'],
    props: {
        label: String,
        prop: String,
        textProp: String,
        width: [String, Number],
        target: {
            type: String,
            default: '_blank'
        }
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        }
    },
    methods: {
        hrefOf(row) {
            const href = getProp(row, this.prop);
            return href ? String(href) : '';
        },
        textOf(row) {
            if (this.textProp) {
                return String(getProp(row, this.textProp) || '');
            }
            return this.hrefOf(row);
        }
    },
    template: `
<el-table-column v-if="parentTag==='m-table'" :prop="prop" :label="resolvedLabel" :width="resolveColumnWidth(width, 220)" :show-overflow-tooltip="true" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <a v-if="hrefOf(row)" :href="hrefOf(row)" :target="target" rel="noopener noreferrer">{{textOf(row)}}</a>
    <span v-else>-</span>
</el-table-column>
<el-form-item v-else :label="resolvedLabel+':'">
    <a v-if="ctx.detail[prop]" :href="ctx.detail[prop]" :target="target" rel="noopener noreferrer">{{textProp ? ctx.detail[textProp] : ctx.detail[prop]}}</a>
    <span v-else>-</span>
</el-form-item>`
});

Vue.component('m-datetime', {
    inject: ['parentTag', 'mode', 'ctx'],
    created() {
        registerLeafFieldDefaults(this, '');
    },
    props: {
        label: String,
        prop: String,
        width: [String, Number],
        disabled: Boolean,
        default: {
            default: ''
        },
        clearable: {
            type: Boolean,
            default: true
        },
        valueFormat: {
            type: String,
            default: 'yyyy-MM-dd HH:mm:ss'
        }
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        }
    },
    template: `
<el-date-picker v-if="parentTag==='m-navbar'" v-model="ctx.request[prop]" type="datetime" size="mini" :clearable="clearable" :style="{width: (width||190)+'px'}" :value-format="valueFormat" v-bind="$attrs"></el-date-picker>
<el-form-item v-else-if="mode==='detail'" :label="resolvedLabel+':'">{{ctx.detail[prop]}}</el-form-item>
<el-form-item v-else :label="resolvedLabel+':'" :prop="prop">
    <el-date-picker v-model="ctx[mode][prop]" type="datetime" :disabled="disabled" :clearable="clearable" :value-format="valueFormat" style="width:100%;" v-bind="$attrs"></el-date-picker>
</el-form-item>`
});

Vue.component('m-enabled', {
    inject: ['ctx'],
    props: {
        label: String,
        prop: {
            type: String,
            default: 'enabled'
        },
        options: [Array, Object, String],
        valueKey: {
            type: String,
            default: 'value'
        },
        labelKey: {
            type: String,
            default: 'label'
        },
        width: [String, Number]
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop, adminT('label.status'));
        },
        statusItems() {
            // Backward compatible default: keep old enabled(0/1) semantics when no external mapping is provided.
            const fallback = {0: adminT('action.disable'), 1: adminT('action.enable')};
            const source = this.options === undefined
                ? fallback
                : (this.isString(this.options) ? this.ctx[this.options] : this.options);
            return normalizeStatusItems(source, this.valueKey, this.labelKey, 'type');
        }
    },
    methods: {
        textOf(value) {
            const target = String(value);
            const found = this.statusItems.find((item) => String(item.value) === target);
            if (found) {
                return found.label;
            }
            return value === null || value === undefined ? '' : String(value);
        }
    },
    template: `
<el-table-column :prop="prop" :label="resolvedLabel" :width="resolveColumnWidth(width, 100)" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <span>{{textOf(getProp(row, prop))}}</span>
</el-table-column>`
});

Vue.component('md-text', {
    inject: ['parentTag', 'ctx'],
    created() {
        assertInjectedCtx('md-text', this.ctx);
        ensureRootOptionsBag(this, this.dictBag);
    },
    mounted() {
        hydrateRootOptionsBag(this, this.dictBag, this.dictUrl);
    },
    props: {
        label: String,
        prop: String,
        dict: String,
        width: [String, Number]
    },
    computed: {
        dictBag() {
            return dictOptionsBagName(this.dict);
        },
        dictUrl() {
            return dictOptionsUrl(this.dict);
        },
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        },
        items() {
            return normalizeDictItems(readRootOptionsBag(this, this.dictBag));
        }
    },
    methods: {
        textOf(value) {
            const target = value === null || value === undefined ? '' : String(value);
            const found = this.items.find((item) => String(item.value) === target);
            return found ? found.label : target;
        }
    },
    template: `
<el-table-column v-if="parentTag==='m-table'" :prop="prop" :label="resolvedLabel" :width="resolveColumnWidth(width, 120)" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <span>{{textOf(getProp(row, prop))}}</span>
</el-table-column>
<el-form-item v-else :label="resolvedLabel+':'">{{textOf(ctx.detail[prop])}}</el-form-item>`
});

Vue.component('md-tag', {
    inject: ['parentTag', 'ctx'],
    created() {
        assertInjectedCtx('md-tag', this.ctx);
        ensureRootOptionsBag(this, this.dictBag);
    },
    mounted() {
        hydrateRootOptionsBag(this, this.dictBag, this.dictUrl);
    },
    props: {
        label: String,
        prop: String,
        dict: String,
        width: [String, Number]
    },
    computed: {
        dictBag() {
            return dictOptionsBagName(this.dict);
        },
        dictUrl() {
            return dictOptionsUrl(this.dict);
        },
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        },
        statusItems() {
            return normalizeStatusItems(normalizeDictItems(readRootOptionsBag(this, this.dictBag)), 'value', 'label', 'type');
        }
    },
    methods: {
        matchStatus(value) {
            const target = String(value);
            const found = this.statusItems.find((item) => String(item.value) === target);
            return found || {value, label: value === null || value === undefined ? '' : String(value), type: ''};
        }
    },
    template: `
<el-table-column v-if="parentTag==='m-table'" :prop="prop" :label="resolvedLabel" :width="resolveColumnWidth(width, 120)" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <template v-for="st in [matchStatus(getProp(row, prop))]">
        <el-tag :key="String(st.value) + '-' + st.label" size="mini" :type="st.type">{{ st.label }}</el-tag>
    </template>
</el-table-column>
<el-form-item v-else :label="resolvedLabel+':'">
    <template v-for="st in [matchStatus(ctx.detail[prop])]">
        <el-tag :key="String(st.value) + '-' + st.label" size="mini" :type="st.type">{{ st.label }}</el-tag>
    </template>
</el-form-item>`
});

const mPercentComponent = {
    inject: ['ctx'],
    props: ['label', 'prop', 'title'],
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        }
    },
    template: `
<el-table-column :prop="prop" :label="resolvedLabel" v-bind="$attrs" v-slot="{row}" width="60">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <slot :row="row"><span :title="title?row[title]:''" :style="{color:getProp(row,prop)>0?'red':'green'}">{{$attrs['formatter']?$attrs['formatter'](row, prop, getProp(row,prop)):getProp(row,prop)}}%</span></slot>
</el-table-column>`
};
Vue.component('m-percent', mPercentComponent);

Vue.component('md-tags', {
    inject: ['ctx'],
    created() {
        assertInjectedCtx('md-tags', this.ctx);
        ensureRootOptionsBag(this, this.dictBag);
    },
    mounted() {
        hydrateRootOptionsBag(this, this.dictBag, this.dictUrl);
    },
    props: {
        label: String,
        prop: String,
        dict: String
    },
    computed: {
        dictBag() {
            return dictOptionsBagName(this.dict);
        },
        dictUrl() {
            return dictOptionsUrl(this.dict);
        },
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        },
        dictItems() {
            return normalizeStatusItems(normalizeDictItems(readRootOptionsBag(this, this.dictBag)), 'value', 'label', 'type');
        }
    },
    template: `
<el-table-column :prop="prop" :label="resolvedLabel" v-bind="$attrs" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <div class="sw-table-tag-cell">
        <el-tag size="mini" v-for="item in extractItems(row[prop])" :key="item.id" :type="item.type">{{item.label}}</el-tag>
    </div>
</el-table-column>`,
    methods: {
        extractItems(data) {
            if (!Array.isArray(data) || data.length === 0) {
                return [];
            }
            return data.map((entry) => {
                const value = typeof entry === 'object' && entry !== null
                    ? (entry.value ?? entry.id ?? entry.item_id ?? entry.role_id ?? entry.permission_id ?? entry.group_id)
                    : entry;
                const found = this.dictItems.find((item) => String(item.value) === String(value));
                if (found) {
                    return {id: found.value, label: found.label, type: found.type};
                }
                const fallback = value === null || value === undefined ? '' : String(value);
                return {id: fallback, label: fallback, type: ''};
            });
        }
    }
});

Vue.component('m-tags', {
    inject: ['ctx'],
    props: ['label', 'prop'],
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        }
    },
    template: `
<el-table-column :prop="prop" :label="resolvedLabel" v-bind="$attrs" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <div class="sw-table-tag-cell">
        <el-tag size="mini" v-for="item in extractItems(row[prop])" :key="item.id">{{item.label}}</el-tag>
    </div>
</el-table-column>`,
    methods: {
        extractItems(data) {
            if (!Array.isArray(data)) {
                return [];
            } else if (!data || data.length === 0) {
                return [];
            } else if (typeof data[0] === 'object') {
                return data.map((v) => {
                    const id =
                        v.role_id ??
                        v.id ??
                        v.value ??
                        v.permission_id ??
                        v.group_id ??
                        v.item_id;
                    let label =
                        v.display_name ??
                        v.label ??
                        v.name ??
                        v.title ??
                        v.role_name ??
                        v.permission_code;
                    if (label === undefined || label === null) {
                        label = id;
                    }
                    if (id === undefined || id === null) {
                        const vals = Object.values(v).filter((x) => x !== undefined && x !== null);
                        return {id: vals[0], label: vals[1] !== undefined ? vals[1] : vals[0]};
                    }
                    return {id, label};
                });
            }
            return data.map(v => ({id: v, label: v}));
        }
    }
});

Vue.component('m-link', {
    inject: ['ctx'],
    props: ['label', 'prop', 'href'],
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop);
        }
    },
    template: `
<el-table-column :prop="prop" :label="resolvedLabel" :width="resolveColumnWidth($attrs.width, 100)" :show-overflow-tooltip="resolveOverflowTooltip($attrs, true)" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <a :href="href+(href.includes('?')?'&':'?')+prop+'='+row[prop]"><slot>{{row[prop]}}</slot></a>
</el-table-column>`
});

Vue.component('m-json-dialog', {
    inject: ['parentTag', 'ctx'],
    props: {
        label: String,
        prop: String,
        width: [String, Number],
        buttonText: {
            type: String,
            default: 'View'
        },
        title: {
            type: String,
            default: 'JSON details'
        }
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop, 'JSON');
        }
    },
    methods: {
        formatJson(value) {
            if (value === null || value === undefined || value === '') {
                return '';
            }
            if (typeof value === 'string') {
                try {
                    return JSON.stringify(JSON.parse(value), null, 2);
                } catch (e) {
                    return value;
                }
            }
            try {
                return JSON.stringify(value, null, 2);
            } catch (e) {
                return String(value);
            }
        },
        showJson(value) {
            const text = this.formatJson(value);
            this.$alert(text || '-', this.title, {
                confirmButtonText: adminT('crud.close')
            });
        }
    },
    template: `
<el-table-column v-if="parentTag==='m-table'" :prop="prop" :label="resolvedLabel" :width="resolveColumnWidth(width, 110)" v-slot="{row}">
    <template slot="header">
        <span :title="columnHeaderNativeTitle(prop)">{{ resolvedLabel }}</span>
    </template>
    <el-button type="text" size="mini" @click="showJson(getProp(row, prop))">{{buttonText}}</el-button>
</el-table-column>
<el-form-item v-else :label="resolvedLabel+':'">
    <el-button type="text" size="mini" @click="showJson(ctx.detail[prop])">{{buttonText}}</el-button>
</el-form-item>`
});

const mActionsComponent = {
    props: {
        actions: {
            type: String,
            default: ''
        },
        moreActions: {
            type: String,
            default: ''
        },
        moreText: {
            type: String,
            default: 'More'
        },
        permissions: {
            type: [Object, String],
            default() {
                return {};
            }
        }
    },
    computed: {
        inlineItems() {
            return this.actions ? this.actions.split(',').map(action => action.trim()).filter(Boolean) : [];
        },
        moreItems() {
            return this.moreActions ? this.moreActions.split(',').map(action => action.trim()).filter(Boolean) : [];
        },
        permissionMap() {
            return parseActionPermissionMap(this.permissions);
        },
        visibleInlineItems() {
            return this.inlineItems.filter(action => this.canRenderAction(action));
        },
        visibleMoreItems() {
            return this.moreItems.filter(action => this.canRenderAction(action));
        },
        width() {
            const MIN_WIDTH = 120;
            const BUTTON_WIDTH = 60;
            const MORE_BUTTON_WIDTH = this.visibleMoreItems.length > 0 ? 70 : 0;
            return Math.max(MIN_WIDTH, this.visibleInlineItems.length * BUTTON_WIDTH + MORE_BUTTON_WIDTH);
        }
    },
    methods: {
        actionComponentName(op) {
            return 'ma-' + op;
        },
        permissionForAction(action) {
            return this.permissionMap[action] || adminInferPermission(action);
        },
        canRenderAction(action) {
            return adminCan(this.permissionForAction(action));
        }
    },
    template: `
<el-table-column fixed="right" :label="adminT('crud.operations')" v-bind="$attrs" v-slot="{row}" :width="width+'px'">
    <template slot="header">
        <span :title="adminT('crud.operations')">{{adminT('crud.operations')}}</span>
    </template>
    <span style="display: inline-block; text-align: center; width: 100%;">
        <template v-for="action in visibleInlineItems">
            <component :is="actionComponentName(action)" :row="row" :permission="permissionForAction(action)" :key="action"></component>
        </template>
        <el-dropdown v-if="visibleMoreItems.length > 0" trigger="click">
            <el-button size="mini" type="text">{{adminT('crud.more')}}<i class="el-icon-arrow-down el-icon--right"></i></el-button>
            <el-dropdown-menu slot="dropdown">
                <el-dropdown-item v-for="action in visibleMoreItems" :key="action">
                    <component :is="actionComponentName(action)" :row="row" :permission="permissionForAction(action)"></component>
                </el-dropdown-item>
            </el-dropdown-menu>
        </el-dropdown>
        <slot :row="row"></slot>
    </span>
</el-table-column>`
};
Vue.component('m-actions', mActionsComponent);

Vue.component('m-button', {
    props: {
        permission: [String, Boolean],
        action: String,
        size: {
            type: String,
            default: 'mini'
        }
    },
    computed: {
        canRender() {
            return adminCan(adminResolvePermission(this.permission, adminInferPermission(this.action || '')));
        }
    },
    template: `<el-button v-if="canRender" :size="size" v-bind="$attrs" @click="$emit('click')"><slot></slot></el-button>`
});

Vue.component('m-time-tag', {
    props: {
        value: [String, Number],
        prefix: {
            type: String,
            default: ''
        },
        type: {
            type: String,
            default: 'info'
        },
        size: {
            type: String,
            default: 'small'
        }
    },
    computed: {
        text() {
            return this.prefix + formatUnixTimeValue(this.value);
        }
    },
    template: `<el-tag :size="size" :type="type">{{text}}</el-tag>`
});

Vue.component('m-post-button', {
    inject: ['ctx'],
    props: {
        action: {
            type: String,
            required: true
        },
        payload: {
            type: Object,
            default() {
                return {};
            }
        },
        confirmText: String,
        reloadAfter: {
            type: Boolean,
            default: true
        },
        disabled: Boolean,
        permission: [String, Boolean],
        size: {
            type: String,
            default: 'mini'
        }
    },
    computed: {
        canRender() {
            return adminCan(adminResolvePermission(this.permission, adminInferPermission(this.action)));
        }
    },
    template: `<el-button v-if="canRender" :size="size" v-bind="$attrs" :disabled="disabled" @click="submit"><slot></slot></el-button>`,
    methods: {
        submit() {
            if (this.disabled || !this.canRender) {
                return;
            }
            const request = () => {
                this.ajaxPost(this.action, Object.assign({}, this.payload), (res) => {
                    this.$emit('success', res);
                    if (!this.reloadAfter) {
                        return;
                    }
                    const reloaded = this.ctx.reload();
                    if (reloaded && typeof reloaded.then === 'function') {
                        reloaded.then(() => this.$emit('reloaded', res));
                    } else {
                        this.$emit('reloaded', res);
                    }
                });
            };
            if (this.confirmText) {
                this.$confirm(this.confirmText).then(request);
                return;
            }
            request();
        }
    }
});

Vue.component('m-batch-button', {
    inject: ['ctx'],
    props: {
        action: {
            type: String,
            required: true
        },
        selectionKey: {
            type: String,
            default: 'ids'
        },
        payload: {
            type: [Object, Function],
            default() {
                return {};
            }
        },
        minSelected: {
            type: Number,
            default: 1
        },
        disabled: Boolean,
        confirmText: String,
        reloadAfter: {
            type: Boolean,
            default: true
        },
        permission: [String, Boolean],
        size: {
            type: String,
            default: 'mini'
        }
    },
    computed: {
        canRender() {
            return adminCan(adminResolvePermission(this.permission, adminInferPermission(this.action)));
        },
        selectedKeys() {
            return Array.isArray(this.ctx.selectedRowKeys) ? this.ctx.selectedRowKeys : [];
        },
        disabledState() {
            return this.disabled || this.selectedKeys.length < this.minSelected;
        }
    },
    template: `<el-button v-if="canRender" :size="size" v-bind="$attrs" :disabled="disabledState" @click="submit"><slot></slot></el-button>`,
    methods: {
        requestPayload() {
            const base = typeof this.payload === 'function'
                ? (this.payload(this.ctx) || {})
                : Object.assign({}, this.payload || {});
            base[this.selectionKey] = this.selectedKeys.slice();
            return base;
        },
        submit() {
            if (this.disabledState || !this.canRender) {
                return;
            }

            const request = () => {
                this.ajaxPost(this.action, this.requestPayload(), (res) => {
                    this.$emit('success', res);
                    if (!this.reloadAfter) {
                        return;
                    }
                    const reloaded = this.ctx.reload();
                    if (reloaded && typeof reloaded.then === 'function') {
                        reloaded.then(() => this.ctx.clearTableSelection());
                    } else {
                        this.ctx.clearTableSelection();
                    }
                });
            };

            if (this.confirmText) {
                this.$confirm(this.confirmText).then(request);
                return;
            }

            request();
        }
    }
});

function uploadPropAttrNames(name) {
    return [
        name,
        String(name)
            .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
            .toLowerCase()
    ];
}

Vue.component('m-upload', {
    inject: ['parentTag', 'mode', 'ctx'],
    created() {
        assertInjectedCtx('m-upload', this.ctx);
        if (this.prop) {
            registerLeafFieldDefaults(this, null);
        }
    },
    props: {
        label: String,
        prop: String,
        action: {
            type: String,
            default: 'upload'
        },
        scene: {
            type: String,
            default: ''
        },
        fieldName: {
            type: String,
            default: 'file'
        },
        valueKey: {
            type: String,
            default: 'asset_id'
        },
        urlProp: String,
        nameProp: String,
        pathProp: String,
        accept: {
            type: String,
            default: ''
        },
        multiple: Boolean,
        disabled: Boolean,
        clearable: {
            type: Boolean,
            default: true
        },
        pickable: {
            type: Boolean,
            default: true
        },
        successText: {
            type: String,
            default: adminT('file.upload') + adminT('common.success')
        },
        autoReload: {
            type: Boolean,
            default: true
        },
        buttonText: {
            type: String,
            default: adminT('file.upload')
        },
        extraData: {
            type: [Object, Function],
            default: null
        },
        default: {
            default: null
        }
    },
    data() {
        return {
            uploadedAsset: null,
            assetDialogVisible: false,
            assetLoading: false,
            assetKeyword: '',
            assetResult: {
                items: [],
                page: 1,
                size: 8,
                count: 0
            },
            assetFetchSeq: 0,
            detailFetchSeq: 0,
            lastDetailValue: ''
        };
    },
    computed: {
        resolvedLabel() {
            return resolveColumnLabel(this.ctx, this.label, this.prop, adminT('table.file'));
        },
        isFieldMode() {
            return this.parentTag === 'm-dialog' && !!this.prop && (this.mode === 'create' || this.mode === 'edit');
        },
        isDetailMode() {
            return this.parentTag === 'm-dialog' && !!this.prop && this.mode === 'detail';
        },
        resolvedMultiple() {
            return this.isFieldMode ? false : this.multiple;
        },
        currentModel() {
            if (!this.prop) {
                return null;
            }
            if (this.mode === 'detail') {
                return this.ctx && this.ctx.detail ? this.ctx.detail : null;
            }
            if (!this.isFieldMode) {
                return null;
            }
            return this.ctx && this.ctx[this.mode] ? this.ctx[this.mode] : null;
        },
        currentValue() {
            if (!this.currentModel || !this.prop) {
                return null;
            }
            return this.currentModel[this.prop];
        },
        currentAssetUrl() {
            const model = this.currentModel;
            if (model) {
                const prop = this.previewSourceProp();
                if (prop && model[prop]) {
                    return String(model[prop]);
                }
            }
            if (this.shouldUseUploadedAsset() && this.uploadedAsset && this.uploadedAsset.asset_url) {
                return String(this.uploadedAsset.asset_url);
            }
            return '';
        },
        currentAssetName() {
            const model = this.currentModel;
            if (model) {
                const prop = this.nameSourceProp();
                if (prop && model[prop]) {
                    return String(model[prop]);
                }
            }
            if (this.shouldUseUploadedAsset() && this.uploadedAsset && this.uploadedAsset.asset_name) {
                return String(this.uploadedAsset.asset_name);
            }
            return '';
        },
        currentAssetPath() {
            const model = this.currentModel;
            if (model && this.pathProp && model[this.pathProp]) {
                return String(model[this.pathProp]);
            }
            if (this.shouldUseUploadedAsset() && this.uploadedAsset && this.uploadedAsset.asset_path) {
                return String(this.uploadedAsset.asset_path);
            }
            return '';
        },
        hasFile() {
            return !!(this.currentAssetUrl || this.currentAssetName || this.nonEmptyBoundValue(this.currentValue));
        },
        canPickExisting() {
            return this.isFieldMode && this.pickable;
        },
        pickerScene() {
            if (this.extraData && typeof this.extraData === 'object' && this.extraData.scene !== undefined && this.extraData.scene !== null && this.extraData.scene !== '') {
                return String(this.extraData.scene);
            }
            return this.scene ? String(this.scene) : '';
        },
        assetRows() {
            const result = this.assetResult;
            return result && Array.isArray(result.items) ? result.items : [];
        },
        assetPage() {
            const result = this.assetResult;
            return result && result.page ? Number(result.page) : 1;
        },
        assetPageSize() {
            const result = this.assetResult;
            return result && result.size ? Number(result.size) : 8;
        },
        assetTotal() {
            const result = this.assetResult;
            return result && result.count ? Number(result.count) : 0;
        },
        isImagePreview() {
            const asset = this.uploadedAsset || {};
            const mimeType = asset && asset.mime_type ? String(asset.mime_type).toLowerCase() : '';
            if (mimeType.startsWith('image/')) {
                return true;
            }
            const url = this.currentAssetUrl.toLowerCase();
            return /\.(png|jpe?g|gif|webp|svg|bmp|ico)(\?.*)?$/.test(url);
        }
    },
    watch: {
        currentValue: {
            immediate: true,
            handler(value) {
                this.ensureBoundAssetLoaded(value);
            }
        }
    },
    methods: {
        propDeclared(name) {
            const propsData = this.$options && this.$options.propsData ? this.$options.propsData : {};
            if (Object.prototype.hasOwnProperty.call(propsData, name)) {
                return true;
            }

            const attrs = this.$vnode && this.$vnode.data && this.$vnode.data.attrs ? this.$vnode.data.attrs : {};
            return uploadPropAttrNames(name).some((key) => Object.prototype.hasOwnProperty.call(attrs, key));
        },
        resolvedAutoReload() {
            if (this.propDeclared('autoReload')) {
                return this.autoReload;
            }
            return !this.isFieldMode;
        },
        previewSourceProp() {
            if (this.urlProp) {
                return this.urlProp;
            }
            if (this.prop === 'asset_url' || String(this.prop || '').endsWith('_url')) {
                return this.prop;
            }
            return '';
        },
        nameSourceProp() {
            if (this.nameProp) {
                return this.nameProp;
            }
            if (this.prop === 'asset_name' || String(this.prop || '').endsWith('_name')) {
                return this.prop;
            }
            return '';
        },
        nonEmptyBoundValue(value) {
            if (value === undefined || value === null || value === '') {
                return false;
            }
            if (this.shouldFetchBoundAssetDetail()) {
                return Number(value) > 0;
            }
            return true;
        },
        shouldFetchBoundAssetDetail() {
            if (this.propDeclared('valueKey')) {
                return this.valueKey === 'asset_id';
            }
            const prop = String(this.prop || '');
            return prop === 'asset_id' || prop.endsWith('_asset_id') || prop.endsWith('_id');
        },
        isImageAssetRow(row) {
            const mimeType = String((row && row.mime_type) || '').toLowerCase();
            if (mimeType.startsWith('image/')) {
                return true;
            }
            const url = String((row && row.asset_url) || '').toLowerCase();
            return /\.(png|jpe?g|gif|webp|svg|bmp|ico)(\?.*)?$/.test(url);
        },
        resolveBoundPayloadKey(payload) {
            if (this.propDeclared('valueKey')) {
                return this.valueKey;
            }
            if (this.prop && payload && Object.prototype.hasOwnProperty.call(payload, this.prop)) {
                return this.prop;
            }
            return this.valueKey;
        },
        resolveBoundPayloadValue(payload) {
            if (!payload || typeof payload !== 'object') {
                return null;
            }
            const key = this.resolveBoundPayloadKey(payload);
            return Object.prototype.hasOwnProperty.call(payload, key) ? payload[key] : null;
        },
        shouldUseUploadedAsset() {
            if (!this.uploadedAsset) {
                return false;
            }
            if (!this.isFieldMode && !this.isDetailMode) {
                return true;
            }
            const bound = this.resolveBoundPayloadValue(this.uploadedAsset);
            if (!this.nonEmptyBoundValue(bound) || !this.nonEmptyBoundValue(this.currentValue)) {
                return false;
            }
            return String(bound) === String(this.currentValue);
        },
        pick() {
            if (this.disabled || !this.$refs.input) {
                return;
            }
            this.$refs.input.click();
        },
        resolveExtraData(file) {
            const extra = {};
            if (typeof this.extraData === 'function') {
                Object.assign(extra, this.extraData(file) || {});
            } else if (this.extraData && typeof this.extraData === 'object') {
                Object.assign(extra, this.extraData);
            }
            if (this.scene && !Object.prototype.hasOwnProperty.call(extra, 'scene')) {
                extra.scene = this.scene;
            }
            return extra;
        },
        applyUploadedAsset(payload) {
            if (!payload || typeof payload !== 'object') {
                return;
            }
            this.uploadedAsset = payload;
            if (!this.isFieldMode || !this.currentModel) {
                return;
            }

            const model = this.currentModel;
            const boundKey = this.resolveBoundPayloadKey(payload);
            if (this.prop && Object.prototype.hasOwnProperty.call(payload, boundKey)) {
                this.$set(model, this.prop, payload[boundKey]);
            }
            if (this.urlProp && Object.prototype.hasOwnProperty.call(payload, 'asset_url')) {
                this.$set(model, this.urlProp, payload.asset_url);
            }
            if (this.nameProp && Object.prototype.hasOwnProperty.call(payload, 'asset_name')) {
                this.$set(model, this.nameProp, payload.asset_name);
            }
            if (this.pathProp && Object.prototype.hasOwnProperty.call(payload, 'asset_path')) {
                this.$set(model, this.pathProp, payload.asset_path);
            }
        },
        clearField() {
            this.uploadedAsset = null;
            this.lastDetailValue = '';
            if (!this.currentModel || !this.prop) {
                return;
            }

            this.$set(this.currentModel, this.prop, this.default);
            if (this.urlProp) {
                this.$set(this.currentModel, this.urlProp, '');
            }
            if (this.nameProp) {
                this.$set(this.currentModel, this.nameProp, '');
            }
            if (this.pathProp) {
                this.$set(this.currentModel, this.pathProp, '');
            }
        },
        ensureBoundAssetLoaded(value) {
            if (!this.isFieldMode) {
                return;
            }
            if (!this.shouldFetchBoundAssetDetail()) {
                return;
            }
            if (!this.nonEmptyBoundValue(value)) {
                this.uploadedAsset = null;
                this.lastDetailValue = '';
                return;
            }
            if (this.previewSourceProp() && this.currentAssetUrl) {
                return;
            }
            if (this.shouldUseUploadedAsset()) {
                return;
            }
            const current = String(value);
            if (this.lastDetailValue === current) {
                return;
            }
            this.lastDetailValue = current;
            const seq = ++this.detailFetchSeq;
            this.ajaxGet('/file/asset/detail', {asset_id: value}, (asset) => {
                if (seq !== this.detailFetchSeq) {
                    return;
                }
                this.applyUploadedAsset(asset);
            });
        },
        openAssetPicker() {
            if (!this.canPickExisting || this.disabled) {
                return;
            }
            this.assetDialogVisible = true;
            this.reloadAssetList(1);
        },
        closeAssetPicker() {
            this.assetDialogVisible = false;
        },
        assetQueryPayload(page = 1) {
            const payload = {
                page,
                size: this.assetPageSize || 8
            };
            if (this.assetKeyword.trim() !== '') {
                payload.keyword = this.assetKeyword.trim();
            }
            if (this.pickerScene !== '') {
                payload.scene = this.pickerScene;
            }
            return payload;
        },
        reloadAssetList(page = 1) {
            const seq = ++this.assetFetchSeq;
            this.assetLoading = true;
            this.ajaxGet('/file/asset/index', this.assetQueryPayload(page), (payload) => {
                if (seq !== this.assetFetchSeq) {
                    return;
                }
                this.assetResult = Object.assign({
                    items: [],
                    page,
                    size: this.assetPageSize || 8,
                    count: 0
                }, payload || {});
                if (!Array.isArray(this.assetResult.items)) {
                    this.assetResult.items = [];
                }
            }).finally(() => {
                if (seq === this.assetFetchSeq) {
                    this.assetLoading = false;
                }
            });
        },
        onAssetKeywordSearch() {
            this.reloadAssetList(1);
        },
        onAssetPageChange(page) {
            this.reloadAssetList(page);
        },
        pickAsset(asset) {
            this.applyUploadedAsset(asset);
            this.assetDialogVisible = false;
            this.$emit('pick', asset);
        },
        onChange(event) {
            const input = event && event.target ? event.target : null;
            const files = input && input.files ? Array.from(input.files) : [];
            if (files.length === 0) {
                return;
            }

            const selectedFiles = this.resolvedMultiple ? files : files.slice(0, 1);
            const requests = selectedFiles.map((file) => {
                const form = new FormData();
                form.append(this.fieldName, file);
                const extra = this.resolveExtraData(file);
                Object.keys(extra).forEach((key) => {
                    if (extra[key] !== undefined && extra[key] !== null) {
                        form.append(key, extra[key]);
                    }
                });
                return this.ajaxPost(this.action, form, (payload) => {
                    this.applyUploadedAsset(payload);
                    if (this.successText) {
                        this.$message({type: 'success', duration: 1200, message: this.successText});
                    }
                    if (this.resolvedAutoReload() && this.ctx && typeof this.ctx.reload === 'function') {
                        this.ctx.reload();
                    }
                    this.$emit('success', payload);
                });
            });

            Promise.all(requests).finally(() => {
                if (input) {
                    input.value = '';
                }
            });
        }
    },
    template: `
<el-form-item v-if="isFieldMode" :label="resolvedLabel+':'" :prop="prop">
    <div style="display:flex;align-items:flex-start;gap:12px;">
        <div style="flex:1;min-width:0;">
            <div v-if="hasFile" style="display:flex;align-items:flex-start;gap:12px;padding:10px 12px;border:1px solid #ebeef5;border-radius:6px;background:#fafafa;">
                <el-image
                    v-if="currentAssetUrl && isImagePreview"
                    :src="currentAssetUrl"
                    :preview-src-list="[currentAssetUrl]"
                    style="width:72px;height:72px;border-radius:6px;flex:none;"
                    fit="cover"
                ></el-image>
                <div style="min-width:0;flex:1;">
                    <div style="font-weight:600;word-break:break-all;">{{currentAssetName || adminT('table.uploaded_file')}}</div>
                    <div v-if="currentAssetUrl" style="margin-top:6px;">
                        <a :href="currentAssetUrl" target="_blank" rel="noopener noreferrer">{{currentAssetUrl}}</a>
                    </div>
                    <div v-if="currentAssetPath" style="margin-top:6px;color:#909399;font-size:12px;word-break:break-all;">{{currentAssetPath}}</div>
                </div>
            </div>
            <div v-else style="padding:10px 12px;border:1px dashed #dcdfe6;border-radius:6px;color:#909399;background:#fafafa;">{{adminT('table.no_uploaded_file')}}</div>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px;">
            <el-button size="mini" v-bind="$attrs" :disabled="disabled" @click="pick"><slot>{{buttonText}}</slot></el-button>
            <el-button v-if="canPickExisting" size="mini" :disabled="disabled" @click="openAssetPicker">{{adminT('action.select')}}</el-button>
            <el-button v-if="clearable && hasFile" size="mini" :disabled="disabled" @click="clearField">{{adminT('action.clear')}}</el-button>
        </div>
    </div>
    <input ref="input" type="file" style="display:none" :accept="accept" :multiple="resolvedMultiple" @change="onChange">
    <el-dialog
        :title="adminT('table.select_file')"
        :visible.sync="assetDialogVisible"
        width="860px"
        append-to-body
    >
        <div style="display:flex;gap:12px;align-items:center;margin-bottom:12px;">
            <el-input
                v-model="assetKeyword"
                size="mini"
                clearable
                :placeholder="adminT('file.keyword_name_mime_ext')"
                style="width:240px;"
                @keyup.enter.native="onAssetKeywordSearch"
                @clear="onAssetKeywordSearch"
            ></el-input>
            <el-tag v-if="pickerScene" size="mini" type="info">{{adminT('label.scene_prefix')}} {{pickerScene}}</el-tag>
            <el-button size="mini" type="primary" @click="onAssetKeywordSearch">{{adminT('action.search')}}</el-button>
        </div>
        <el-table :data="assetRows" border size="mini" v-loading="assetLoading">
            <el-table-column :label="adminT('table.preview')" width="88" v-slot="{row}">
                <el-image
                    v-if="isImageAssetRow(row)"
                    :src="row.asset_url"
                    :preview-src-list="[row.asset_url]"
                    style="width:48px;height:48px;border-radius:4px;"
                    fit="cover"
                ></el-image>
                <a v-else-if="row.asset_url" :href="row.asset_url" target="_blank" rel="noopener noreferrer">{{adminT('table.view')}}</a>
                <span v-else>-</span>
            </el-table-column>
            <el-table-column prop="asset_name" :label="adminT('file.name')" min-width="220" show-overflow-tooltip></el-table-column>
            <el-table-column prop="scene" :label="adminT('file.scene')" width="110"></el-table-column>
            <el-table-column prop="asset_ext" :label="adminT('label.ext')" width="90"></el-table-column>
            <el-table-column prop="mime_type" :label="adminT('label.mime')" min-width="180" show-overflow-tooltip></el-table-column>
            <el-table-column :label="adminT('label.action')" width="88" v-slot="{row}">
                <el-button size="mini" type="primary" @click="pickAsset(row)">{{adminT('action.select')}}</el-button>
            </el-table-column>
        </el-table>
        <div style="display:flex;justify-content:flex-end;margin-top:12px;">
            <el-pagination
                background
                layout="prev, pager, next, total"
                :current-page="assetPage"
                :page-size="assetPageSize"
                :total="assetTotal"
                @current-change="onAssetPageChange"
            ></el-pagination>
        </div>
    </el-dialog>
</el-form-item>
<el-form-item v-else-if="isDetailMode" :label="resolvedLabel+':'">
    <div v-if="hasFile" style="display:flex;align-items:flex-start;gap:12px;">
        <el-image
            v-if="currentAssetUrl && isImagePreview"
            :src="currentAssetUrl"
            :preview-src-list="[currentAssetUrl]"
            style="width:96px;height:96px;border-radius:6px;flex:none;"
            fit="cover"
        ></el-image>
        <div style="min-width:0;flex:1;">
            <div style="font-weight:600;word-break:break-all;">{{currentAssetName || adminT('table.file')}}</div>
            <div v-if="currentAssetUrl" style="margin-top:6px;">
                <a :href="currentAssetUrl" target="_blank" rel="noopener noreferrer">{{currentAssetUrl}}</a>
            </div>
            <div v-if="currentAssetPath" style="margin-top:6px;color:#909399;font-size:12px;word-break:break-all;">{{currentAssetPath}}</div>
        </div>
    </div>
    <span v-else>-</span>
</el-form-item>
<span v-else>
    <el-button size="mini" v-bind="$attrs" :disabled="disabled" @click="pick"><slot>{{buttonText}}</slot></el-button>
    <input ref="input" type="file" style="display:none" :accept="accept" :multiple="resolvedMultiple" @change="onChange">
</span>`
});
