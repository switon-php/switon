/**
 * Axios setup and shared ajax helpers.
 *
 * Load order: ui.js -> core.js -> layout.js -> components.js -> bootstrap.js.
 * Road-sign: request/response interception, ajaxGet/ajaxPost, and root loading state start here.
 */
if (typeof Vue !== 'undefined' && Vue.config) {
    Vue.config.productionTip = false;
}
Vue.prototype.$axios = axios;
Vue.prototype.$moment = moment;
Vue.prototype.$qs = Qs;
Vue.prototype._ = _;
Vue.prototype.sessionStorage = window.sessionStorage;
Vue.prototype.localStorage = window.localStorage;
Vue.prototype.console = console;
Vue.prototype.adminT = window.adminT;

const INSPECTOR_BATCH_WINDOW_MS = 1000;
const INSPECTOR_MAX_LINKS = 10;
const INSPECTOR_COUNTER_LIMIT = 100;
const INSPECTOR_UI_SYNC_FALLBACK_MS = 16;
const AXIOS_CACHE_TTL_MS = 5 * 60 * 1000;

/** @type {Vue | null} Set from App `created()`; used by axios interceptors before child `created` runs. */
let adminPageRootVm = null;
let inspectorUiSyncScheduled = false;

/**
 * Called from `App` `created()` so interceptors resolve the page root before any child fires AJAX.
 * @param {Vue} instance Root vm (typically `App` instance).
 * @returns {void}
 */
function registerAdminPageRoot(instance) {
    adminPageRootVm = instance;
}

/**
 * Root vm for loading flag and Element toasts; falls back to legacy global `vm` if unset.
 * @returns {Vue|null}
 */
function getAdminPageRoot() {
    if (adminPageRootVm) {
        return adminPageRootVm;
    }
    if (typeof vm !== 'undefined' && vm) {
        return vm;
    }
    return null;
}

/** Sync parsed query onto document.location (read by legacy code); call after history.replaceState or on load. */
function refreshDocumentLocationQuery() {
    document.location.query =
        document.location.search !== '' ? Qs.parse(document.location.search.substring(1)) : {};
}

/**
 * True when a request/query value should be omitted from URL sync (shared with navbar empty checks).
 * @param {*} v
 * @returns {boolean}
 */
function isEmptyUrlQueryValue(v) {
    if (v === undefined || v === null) {
        return true;
    }
    if (typeof v === 'string' && v.trim() === '') {
        return true;
    }
    if (Array.isArray(v)) {
        if (v.length === 0) {
            return true;
        }
        return v.every(
            (item) =>
                item === undefined ||
                item === null ||
                item === '' ||
                (typeof item === 'string' && item.trim() === '')
        );
    }
    return false;
}

/**
 * True when `key` must stay in the address bar (and hydrate from URL) even if the value is empty string.
 * Configure via {@link window.AdminUiDefaults}.keepEmptyQueryKeys (mutable array on the frozen defaults object).
 * @param {string} key Request / query param name.
 * @returns {boolean}
 */
function isKeepEmptyQueryKey(key) {
    if (typeof key !== 'string' || key === '') {
        return false;
    }
    const list =
        typeof window !== 'undefined' && window.AdminUiDefaults && window.AdminUiDefaults.keepEmptyQueryKeys;
    if (!Array.isArray(list)) {
        return false;
    }
    return list.indexOf(key) !== -1;
}

function setVmLoading(flag) {
    const root = getAdminPageRoot();
    if (root && typeof root.loading === 'boolean') {
        root.loading = flag;
    }
}

function appendAjaxFlag(url) {
    return url + (url.indexOf('?') === -1 ? '?ajax' : '&ajax');
}

/**
 * API JSON envelope: treat as success when `code` is numeric 0 or string `'0'`.
 * @param {*} code
 * @returns {boolean}
 */
function isApiSuccessCode(code) {
    return code === 0 || code === '0';
}

/**
 * Update shell-level unread signal badge and notify listeners such as menu tabs / avatar badge.
 * @param {number|string} count
 * @returns {void}
 */
function syncAdminSignalCount(count) {
    const next = Number(count || 0);
    const state = typeof adminShellState === 'function' ? adminShellState() : null;
    if (state) {
        state.messageCount = next;
    }
    if (typeof window.dispatchEvent === 'function' && typeof CustomEvent === 'function') {
        window.dispatchEvent(new CustomEvent('admin-signal-count-change', {
            detail: {count: next}
        }));
    }
}

function resolveInspectorUrl(res) {
    if (res.headers && res.headers['x-inspector-link']) {
        return res.headers['x-inspector-link'];
    }
    if (res.data && res.data.inspector) {
        return res.data.inspector;
    }
    return null;
}

function scheduleInspectorPanelSync() {
    if (!window.inspectorComponent || inspectorUiSyncScheduled) {
        return;
    }
    inspectorUiSyncScheduled = true;
    const flush = () => {
        inspectorUiSyncScheduled = false;
        if (!window.inspectorComponent) {
            return;
        }
        window.inspectorComponent.links = [...window.inspectorLinks];
        window.inspectorComponent.$forceUpdate();
    };
    if (typeof window.requestAnimationFrame === 'function') {
        window.requestAnimationFrame(flush);
        return;
    }
    setTimeout(flush, INSPECTOR_UI_SYNC_FALLBACK_MS);
}

function trackInspectorLink(res) {
    const inspectorUrl = resolveInspectorUrl(res);
    if (!inspectorUrl) {
        return;
    }

    const now = Date.now();
    const timeSinceLastRequest = now - window.lastRequestTime;
    if (timeSinceLastRequest > INSPECTOR_BATCH_WINDOW_MS || window.lastRequestTime === 0) {
        window.currentBatchId++;
    }

    window.lastRequestTime = now;
    window.inspectorLinkCounter++;

    if (window.inspectorLinkCounter >= INSPECTOR_COUNTER_LIMIT) {
        window.inspectorLinkCounter = 0;
    }

    window.inspectorLinks.push({
        url: inspectorUrl,
        requestUrl: res.config.url,
        method: (res.config.method || 'GET').toUpperCase(),
        index: window.inspectorLinkCounter,
        timestamp: now,
        batchId: window.currentBatchId
    });

    if (window.inspectorLinks.length > INSPECTOR_MAX_LINKS) {
        window.inspectorLinks.shift();
    }

    scheduleInspectorPanelSync();
}

function handleResponseMessages(res) {
    const root = getAdminPageRoot();
    if (!root) {
        return;
    }
    if (typeof res.data === 'string') {
        root.$alert(res.data, adminT('common.server_error'), {customClass: 'error-response'});
    }

    if (!isApiSuccessCode(res.data.code)) {
        return;
    }

    if (res.data.msg) {
        root.$message({type: 'success', duration: 1000, message: res.data.msg});
    } else if (res.config.method !== 'get') {
        root.$message({type: 'success', duration: 1000, message: adminT('common.success')});
    }
}

function handleAxiosError(error) {
    setVmLoading(false);

    console.log(error);
    const status = error.response && error.response.status;
    const data = error.response && error.response.data;
    const msg = data && typeof data === 'object' && data.msg != null ? data.msg : null;

    if (status) {
        switch (status) {
            case 400:
                alert(msg || adminT('common.request_invalid'));
                break;
            case 401:
                window.location.href =
                    window.BASE_URL +
                    '/login?redirect=' +
                    encodeURIComponent(window.location.href);
                break;
            case 403:
                alert(msg || adminT('common.forbidden'));
                break;
            case 404:
                alert(msg || adminT('common.not_found'));
                break;
            default:
                alert(msg || (adminT('common.network_error_retry') + ': ' + status));
                break;
        }
    } else {
        alert(adminT('common.network_error_retry'));
    }
}

(function () {
    const urlKey = `last_url_query.${document.location.pathname}`;
    window.onbeforeunload = () => {
        sessionStorage.setItem(urlKey, document.location.search);
    };

    if (document.location.search !== '' || document.referrer === '') {
        return;
    }
    const lastUrlQuery = sessionStorage.getItem(urlKey);
    window.history.replaceState(null, null, lastUrlQuery === null ? '' : lastUrlQuery);
}());

refreshDocumentLocationQuery();

// Initialize inspector link tracking.
window.inspectorLinks = [];
window.inspectorLinkCounter = 0;
window.lastRequestTime = 0;
window.currentBatchId = 0;

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
// So server-side wantsJson() sees JSON preference (ViewGetMapping list reload, APIs).
axios.defaults.headers.common['Accept'] = 'application/json, text/plain, */*';

axios.interceptors.request.use(function (config) {
    setVmLoading(true);

    const url = config.url == null ? '' : String(config.url);
    if (url.startsWith('/')) {
        config.url = BASE_URL + url;
    }

    config.url = appendAjaxFlag(config.url);
    return config;
});

axios.interceptors.response.use(
    function (res) {
        setVmLoading(false);
        handleResponseMessages(res);
        trackInspectorLink(res);
        return res;
    },
    function (error) {
        handleAxiosError(error);
        return Promise.reject(error);
    }
);

if (typeof window.__axiosCacheEnabled !== 'boolean') {
    // Persist switch state across sessions.
    window.__axiosCacheEnabled = localStorage.getItem('axios.cache.enabled') === '1';
}

function resolveAxiosCacheKey(url) {
    if (!url.match(/\bcache=[12]\b/) || window.__axiosCacheEnabled !== true) {
        return null;
    }
    return 'axios.cache.' + url;
}

function cleanupAxiosCache() {
    const now = Date.now();
    const staleKeys = [];
    for (let i = 0; i < sessionStorage.length; i++) {
        const key = sessionStorage.key(i);
        if (!key || key.indexOf('axios.cache.') !== 0) {
            continue;
        }
        try {
            const raw = sessionStorage.getItem(key);
            if (!raw) {
                staleKeys.push(key);
                continue;
            }
            const parsed = JSON.parse(raw);
            if (parsed && typeof parsed === 'object' && parsed.expiresAt && parsed.expiresAt <= now) {
                staleKeys.push(key);
            }
        } catch (e) {
            staleKeys.push(key);
        }
    }
    staleKeys.forEach((key) => sessionStorage.removeItem(key));
}

function readAxiosCache(cacheKey) {
    if (!cacheKey) {
        return null;
    }
    const cacheValue = sessionStorage.getItem(cacheKey);
    if (!cacheValue) {
        return null;
    }

    const now = Date.now();
    try {
        const parsed = JSON.parse(cacheValue);
        if (parsed && typeof parsed === 'object' && parsed.data !== undefined) {
            if (!parsed.expiresAt || parsed.expiresAt > now) {
                return parsed.data;
            }
            sessionStorage.removeItem(cacheKey);
            return null;
        }

        return parsed;
    } catch (e) {
        sessionStorage.removeItem(cacheKey);
        return null;
    }
}

function writeAxiosCache(cacheKey, data) {
    if (!cacheKey) {
        return;
    }
    sessionStorage.setItem(
        cacheKey,
        JSON.stringify({
            data,
            expiresAt: Date.now() + AXIOS_CACHE_TTL_MS
        })
    );
}

function adminCachedGet(url, success) {
    const cacheKey = resolveAxiosCacheKey(url);
    if (cacheKey) {
        cleanupAxiosCache();
        const cached = readAxiosCache(cacheKey);
        if (cached !== null) {
            if (success) {
                success(cached);
            }
            return Promise.resolve(cached);
        }
    }

    return axios.get(url).then((res) => {
        if (isApiSuccessCode(res.data.code)) {
            if (cacheKey) {
                writeAxiosCache(cacheKey, res.data.data);
            }
            if (success) {
                success(res.data.data);
            }
            return res.data.data;
        }
        if (res.data && res.data.msg) {
            alert(res.data.msg);
        }
        return null;
    });
}

/**
 * GET with `{ code, data, msg? }` envelope. Appends query from `data` when object. URL may include `cache=1|2` for sessionStorage.
 * @this {Vue}
 * @param {string} url Absolute or path (`/` prefix → `BASE_URL` in request interceptor).
 * @param {Object|function(any):void} [data] Query object, or omit and pass `success` as second arg.
 * @param {function(any):void} [success] Receives `res.data.data` when `code` is success.
 * @returns {Promise|undefined} Undefined when response served only from sessionStorage cache hit.
 */
Vue.prototype.ajaxGet = function (url, data, success) {
    if (typeof data === 'function') {
        success = data;
        data = null;
    } else if (data) {
        url += (url.indexOf('?') === -1 ? '?' : '&') + Qs.stringify(data);
    }

    const cacheKey = success ? resolveAxiosCacheKey(url) : null;
    if (cacheKey) {
        cleanupAxiosCache();
        const cached = readAxiosCache(cacheKey);
        if (cached !== null) {
            success.call(this, cached);
            return;
        }
    }

    return this.$axios.get(url).then((res) => {
        if (isApiSuccessCode(res.data.code)) {
            if (success) {
                if (cacheKey) {
                    writeAxiosCache(cacheKey, res.data.data);
                }
                success.call(this, res.data.data);
            }
        } else if (res.data.msg) {
            this.$alert(res.data.msg);
        }
        return res;
    });
};

/**
 * POST with same `{ code, data, msg? }` envelope as `ajaxGet`. Non-success `msg` may trigger `$alert`.
 * @this {Vue}
 * @param {string} url Relative action name or full URL (list pages often use short names like `'delete'`).
 * @param {Object|FormData|function(any):void} [data] Body; use `FormData` for uploads. If function, treated as `success` and body `{}`.
 * @param {function(any):void} [success] Receives `res.data.data` on success.
 * @returns {Promise}
 */
Vue.prototype.ajaxPost = function (url, data, success) {
    if (typeof data === 'function') {
        success = data;
        data = {};
    }

    let config = {};
    if (data instanceof FormData) {
        config.headers = {'Content-Type': 'multipart/form-data'};
    }

    return this.$axios.post(url, data, config).then((res) => {
        if (isApiSuccessCode(res.data.code) && success) {
            success.call(this, res.data.data);
        }

        if (typeof res.data.msg === 'string' && res.data.msg !== '') {
            this.$alert(res.data.msg);
        }
        return res;
    });
};

Vue.filter('date', function (value, format = 'YYYY-MM-DD HH:mm:ss') {
    return value ? moment(value * 1000).format(format) : '';
});

Vue.filter('json', function (value) {
    let parsed = value;
    if (typeof value === 'string') {
        try {
            parsed = JSON.parse(value);
        } catch (e) {
            return value;
        }
    }
    try {
        return JSON.stringify(parsed, null, 2);
    } catch (e) {
        return String(value);
    }
});
