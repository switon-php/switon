/**
 * List page App constructor and shared page helpers.
 * Road-sign: list page state, ctx proxy, reload, and show_edit flow start here.
 */
const DEFAULT_LABELS = window.AdminUiDefaults ? window.AdminUiDefaults.labels : {};

/**
 * Writes `request` fields to the address bar and refreshes `document.location.query`.
 * Empty strings are omitted unless the key is listed in `AdminUiDefaults.keepEmptyQueryKeys`.
 * @param {Object} request Typically `ctx.request` (filters + pager).
 * @returns {void}
 */
function syncUrlQuery(request) {
    const cleaned = {};
    Object.keys(request || {}).forEach((k) => {
        const v = request[k];
        if (v === '' && isKeepEmptyQueryKey(k)) {
            cleaned[k] = v;
            return;
        }
        if (isEmptyUrlQueryValue(v)) {
            return;
        }
        cleaned[k] = v;
    });
    const qs = Qs.stringify(cleaned);
    window.history.replaceState(null, null, qs ? '?' + qs : '');
    refreshDocumentLocationQuery();
}

Vue.prototype.syncUrlQuery = syncUrlQuery;

/**
 * @typedef {Object} PageContext
 * @property {Object} request
 * @property {Object|Array|null} response
 * @property {Object} create
 * @property {Object} edit
 * @property {Object} detail
 * @property {Object} label
 * @property {string} topic
 * @property {boolean} createVisible
 * @property {boolean} editVisible
 * @property {boolean} detailVisible
 * @property {boolean} hasDetail
 * @property {Object} $refs
 * @property {Function} reload
 * @property {Function} show_edit
 * @property {Object|undefined} [__dialogModelFields] Internal: inferred create/edit field map from dialog slots.
 */

/**
 * Options merged by `Vue.extend` when a page does `new App({ ... })`.
 * @typedef {Object} ListAppOptions
 * @property {Object} [data] Merged onto defaults: `topic`, `statuses`, `roles`, `bags`, `label`, optional `model` for form DSL.
 * @property {Object} [methods] Page actions, e.g. `do_scan`, or override `show_edit`.
 * @property {Object} [computed]
 * @property {Object} [watch]
 */

/**
 * Build a stable page context proxy for components.
 * Components read/write via ctx instead of touching root directly.
 *
 * @param {Vue} vm
 * @returns {PageContext}
 */
function createPageContext(vm) {
    const ctx = {};
    const keys = [
        'request',
        'response',
        'create',
        'edit',
        'detail',
        'label',
        'topic',
        'createVisible',
        'editVisible',
        'detailVisible',
        'hasDetail',
        'selectedRows',
        'selectedRowKeys',
        'tablePrefs'
    ];

    keys.forEach((key) => {
        Object.defineProperty(ctx, key, {
            enumerable: true,
            get() {
                return vm[key];
            },
            set(value) {
                vm[key] = value;
            }
        });
    });

    Object.defineProperty(ctx, '$refs', {
        enumerable: true,
        get() {
            return vm.$refs;
        }
    });

    ctx.reload = vm.reload.bind(vm);
    ctx.show_edit = vm.show_edit.bind(vm);
    ctx.clearTableSelection = vm.clearTableSelection.bind(vm);
    ctx.updateTablePrefs = vm.updateTablePrefs.bind(vm);

    return ctx;
}

/**
 * Merges static `model` with create/edit patches from page `data`. `undefined` in patch removes key; `null` is kept.
 * @param {Object} model
 * @param {Object} modePatch
 * @returns {Object}
 */
function mergeModelWithMode(model, modePatch) {
    const result = Object.assign({}, model || {});
    Object.keys(modePatch || {}).forEach((key) => {
        // DSL merge rule:
        // - undefined in mode patch means "remove this field from final runtime model"
        // - null is preserved and will be submitted as null
        if (modePatch[key] === undefined) {
            delete result[key];
        } else {
            result[key] = modePatch[key];
        }
    });
    return result;
}

/**
 * Debounced list reload for filter changes (shared by request deep-watch and m-date @change).
 * Element UI daterange does not always trigger root `request` deep watchers reliably in Vue 2.
 */
Vue.prototype.scheduleRequestReload = function () {
    if (!this._scheduleRequestReloadDebounced) {
        this._scheduleRequestReloadDebounced = _.debounce(() => {
            if (typeof this.reload === 'function') {
                this.reload();
            }
        }, 500);
    }
    this._scheduleRequestReloadDebounced();
};

Vue.prototype.auto_reload = function () {
    const raw = window.location.search && window.location.search.length > 1 ? window.location.search.substring(1) : '';
    const qs = this.$qs.parse(raw) || {};
    for (const k in qs) {
        if (Object.prototype.hasOwnProperty.call(qs, k)) {
            this.$set(this.request, k, qs[k]);
        }
    }

    // Empty request {} and no query → skip list fetch (declared on App data so navbar v-model never sees null).
    const shouldLoad = Object.keys(this.request).length > 0;
    if (!shouldLoad) {
        return Promise.resolve();
    }

    return this.reload().then(() => {
        this.$watch('request', () => this.scheduleRequestReload(), {deep: true});
    });
};

Vue.prototype.isString = function (v) {
    return typeof v === 'string';
};

Vue.prototype.getProp = function (row, prop) {
    if (prop === undefined) {
        return '';
    }
    let v = row;
    for (let part of prop.split('.')) {
        if (v === null) {
            return '';
        }
        if (!Object.prototype.hasOwnProperty.call(v, part)) {
            return '';
        }
        v = v[part];
    }

    return v;
};

/**
 * List-page Vue constructor. Typical: `vm = new App({ data: { topic: '…' }, methods: { … } }); vm.$mount('#app').auto_reload();`
 * @global
 * @see ListAppOptions
 */
App = Vue.extend({
    data() {
        return {
            topic: '',
            createTopic: '',
            createActionText: adminT('crud.create'),
            label: Object.assign({}, DEFAULT_LABELS),
            ctx: null,
            loading: false,
            loaded: false,
            response: null,
            create: {},
            edit: {},
            createVisible: false,
            editVisible: false,
            detailVisible: false,
            detail: {},
            selectedRows: [],
            selectedRowKeys: [],
            tablePrefs: {},
            /** List filters + pager params; always an object so navbar fields never bind to null/undefined. */
            request: {},
            /** Holds m-select `options="bagName"` when the page does not declare `bagName` on root. */
            bags: {},
            /** Left sidebar narrow mode (icons only); persisted in localStorage. */
            sidebarCollapsed: false,
            /** True after the main content pane scrolls, so sticky shell chrome can switch to compact mode. */
            mainScrolled: false,
            /** True when main pane reaches the bottom, so sticky pager can fall back to the normal inline state. */
            mainAtBottom: false
        };
    },
    created() {
        registerAdminPageRoot(this);
        try {
            if (typeof localStorage !== 'undefined' && localStorage.getItem('switon.admin.sidebarCollapsed') === '1') {
                this.sidebarCollapsed = true;
            }
        } catch (e) {
            /* ignore */
        }
        try {
            if (typeof window !== 'undefined' && window.matchMedia && window.matchMedia('(max-width: 900px)').matches) {
                this.sidebarCollapsed = false;
            }
        } catch (e) {
            /* ignore */
        }
        this.ctx = createPageContext(this);
        // Opt-in: when page provides model definition, build runtime form models automatically.
        const hasModelDsl = this.model && typeof this.model === 'object';
        if (hasModelDsl) {
            const createPatch = this.create && typeof this.create === 'object' ? this.create : {};
            const editPatch = this.edit && typeof this.edit === 'object' ? this.edit : {};
            this._createModel = mergeModelWithMode(this.model, createPatch);
            this._editModel = mergeModelWithMode(this.model, editPatch);
            this.create = Object.assign({}, this._createModel);
            this.edit = Object.assign({}, this._editModel);
        }
    },
    mounted() {
        this._syncSidebarForViewport = () => {
            if (typeof window === 'undefined' || !window.matchMedia) {
                return;
            }
            if (window.matchMedia('(max-width: 900px)').matches && this.sidebarCollapsed) {
                this.sidebarCollapsed = false;
            }
        };
        this._syncMainScrollState = () => {
            if (!this._mainScrollEl) {
                this.mainScrolled = false;
                this.mainAtBottom = false;
                return;
            }
            const el = this._mainScrollEl;
            const threshold = 4;
            const bottomEnterThreshold = 12;
            const bottomLeaveThreshold = 48;
            const maxScrollTop = Math.max(0, el.scrollHeight - el.clientHeight);
            this.mainScrolled = el.scrollTop > threshold;
            if (maxScrollTop <= threshold) {
                this.mainAtBottom = false;
                return;
            }
            const distanceToBottom = Math.max(0, maxScrollTop - el.scrollTop);
            if (this.mainAtBottom) {
                this.mainAtBottom = distanceToBottom <= bottomLeaveThreshold;
                return;
            }
            this.mainAtBottom = distanceToBottom <= bottomEnterThreshold;
        };
        this._mainScrollEl = this.$el ? this.$el.querySelector('.el-main') : null;
        window.addEventListener('resize', this._syncSidebarForViewport);
        if (this._mainScrollEl) {
            this._mainScrollEl.addEventListener('scroll', this._syncMainScrollState, {passive: true});
            this._syncMainScrollState();
        }
        this._syncSidebarForViewport();
    },
    beforeDestroy() {
        if (this._syncSidebarForViewport) {
            window.removeEventListener('resize', this._syncSidebarForViewport);
        }
        if (this._mainScrollEl && this._syncMainScrollState) {
            this._mainScrollEl.removeEventListener('scroll', this._syncMainScrollState);
        }
    },
    methods: {
        getInferredModeModel(mode) {
            const store = this.ctx && this.ctx.__dialogModelFields;
            if (!store || typeof store !== 'object') {
                return null;
            }
            const inferred = store[mode];
            if (!inferred || typeof inferred !== 'object') {
                return null;
            }
            const keys = Object.keys(inferred);
            if (keys.length === 0) {
                return null;
            }
            return Object.assign({}, inferred);
        },
        composeEdit(row = {}, overwrite = {}) {
            const base = this._editModel && typeof this._editModel === 'object' ? this._editModel : {};
            return Object.assign({}, base, row || {}, overwrite || {});
        },
        resetCreate() {
            if (this._createModel && typeof this._createModel === 'object') {
                this.create = Object.assign({}, this._createModel);
            }
        },
        resetEdit() {
            if (this._editModel && typeof this._editModel === 'object') {
                this.edit = Object.assign({}, this._editModel);
            }
        },
        reload() {
            const req = this.request;
            if (req != null && typeof req !== 'object') {
                alert('bad reload');
                return Promise.reject(new Error('bad reload'));
            }

            if (req != null) {
                syncUrlQuery(req);
            }

            this._reloadSeq = (this._reloadSeq || 0) + 1;
            const seq = this._reloadSeq;

            return this.$axios
                .get(document.location.href, {
                    headers: {
                        Accept: 'application/json, text/plain, */*',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                })
                .then((res) => {
                    if (seq !== this._reloadSeq) {
                        return res;
                    }
                    if (!res.data || typeof res.data !== 'object' || !isApiSuccessCode(res.data.code)) {
                        const msg =
                            res.data && typeof res.data === 'object' && res.data.msg != null
                                ? res.data.msg
                                : adminT('crud.bad_list_response');
                        this.$alert(msg);
                    } else {
                        this.response = res.data.data;
                    }
                    return res;
                })
                .finally(() => {
                    this.loaded = true;
                });
        },
        show_edit(row, overwrite = {}) {
            if (this._editModel && typeof this._editModel === 'object') {
                this.edit = this.composeEdit(row, overwrite);
                this.editVisible = true;
                return;
            }
            const inferredEdit = this.getInferredModeModel('edit');
            if (inferredEdit) {
                const nextEdit = {};
                const pk = resolvePrimaryKey(row);
                if (pk && Object.prototype.hasOwnProperty.call(row || {}, pk)) {
                    nextEdit[pk] = row[pk];
                }
                Object.assign(nextEdit, inferredEdit);
                Object.keys(nextEdit).forEach((key) => {
                    if (row && Object.prototype.hasOwnProperty.call(row, key)) {
                        nextEdit[key] = row[key];
                    }
                });
                for (let key in overwrite) {
                    nextEdit[key] = overwrite[key];
                }
                this.edit = nextEdit;
            } else if (Object.keys(this.edit || {}).length === 0) {
                this.edit = Object.assign({}, row, overwrite);
            } else {
                for (let key in this.edit) {
                    if (row && Object.prototype.hasOwnProperty.call(row, key)) {
                        this.edit[key] = row[key];
                    }
                }
                for (let key in overwrite) {
                    this.edit[key] = overwrite[key];
                }
            }

            this.editVisible = true;
        },
        toggleSidebarCollapse() {
            this.sidebarCollapsed = !this.sidebarCollapsed;
            try {
                if (typeof localStorage !== 'undefined') {
                    localStorage.setItem('switon.admin.sidebarCollapsed', this.sidebarCollapsed ? '1' : '0');
                }
            } catch (e) {
                /* ignore */
            }
        },
        clearTableSelection() {
            this.selectedRows = [];
            this.selectedRowKeys = [];
            if (typeof this._clearTableSelection === 'function') {
                this._clearTableSelection();
            }
        },
        updateTablePrefs(nextPrefs) {
            this.tablePrefs = Object.assign({}, nextPrefs || {});
        }
    }
});
