/**
 * Shell-level Vue components.
 */
/** @type {string[]} Allowed `data-admin-theme` / localStorage `switon.admin.theme` ids (matches admin-theme-skins.css). */
var ADMIN_THEME_IDS = ['light', 'dark'];
var ADMIN_RECENT_TAB_LIMIT = 7;
var ADMIN_MENU_URL = '/menu/my/index?cache=2';
var ADMIN_RBAC_URL = '/rbac/my/index?cache=2';

/** Old theme ids → light | dark (localStorage migration). */
var ADMIN_THEME_LEGACY = {
    switon: 'light',
    beacon: 'light',
    forest: 'light',
    ocean: 'light',
    ember: 'light',
    lilac: 'light',
    slate: 'light',
    crimson: 'light',
    azure: 'light',
    coral: 'light',
    mint: 'light',
    midnight: 'light',
    gold: 'light',
    rose: 'light',
    copper: 'light',
    frost: 'light',
    lagoon: 'light',
    cherry: 'light',
    graphite: 'light',
    moss: 'light',
    plum: 'light',
    mui: 'light',
    semi: 'light',
    naive: 'light',
    linear: 'light',
    notion: 'light',
    chakra: 'light',
    tailwind: 'light',
    vercel: 'light',
    dracula: 'dark',
    antd: 'light',
    arco: 'light',
    bootstrap: 'light',
    carbon: 'light',
    element: 'light',
    fluent: 'light',
    github: 'light',
    radix: 'light',
    shadcn: 'light',
    stripe: 'light'
};

function resolveAdminTheme(value) {
    if (value && ADMIN_THEME_LEGACY[value]) {
        return ADMIN_THEME_LEGACY[value];
    }
    return ADMIN_THEME_IDS.indexOf(value) >= 0 ? value : 'light';
}

function trimStoredTabs(tabs) {
    const entries = Object.entries(tabs || {}).filter((entry) => {
        const tab = entry[1];
        return tab && typeof tab.name === 'string' && typeof tab.url === 'string';
    });
    return Object.fromEntries(entries.slice(-ADMIN_RECENT_TAB_LIMIT));
}

function saveStoredTabs(tabs) {
    sessionStorage.setItem('.my-menu-tabs', JSON.stringify(trimStoredTabs(tabs)));
}

function getStoredTabs() {
    const raw = sessionStorage.getItem('.my-menu-tabs');
    if (!raw) {
        return {};
    }
    try {
        const parsed = JSON.parse(raw);
        const normalized = parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? trimStoredTabs(parsed) : {};
        if (JSON.stringify(normalized) !== raw) {
            saveStoredTabs(normalized);
        }
        return normalized;
    } catch (e) {
        return {};
    }
}

function createAdminShellState() {
    return {
        userName: 'User',
        roles: [],
        isSuperuser: false,
        permissions: [],
        knownPermissions: [],
        menuGroups: null,
        messageCount: 0
    };
}

function adminShellState() {
    if (!window.__ADMIN_SHELL_STATE__ || typeof window.__ADMIN_SHELL_STATE__ !== 'object') {
        window.__ADMIN_SHELL_STATE__ = createAdminShellState();
    }
    return window.__ADMIN_SHELL_STATE__;
}

function applyAdminMenuState(payload) {
    const state = adminShellState();
    state.menuGroups = Array.isArray(payload) ? payload : null;
    return state;
}

function applyAdminRbacState(payload) {
    const state = adminShellState();
    const data = payload && typeof payload === 'object' ? payload : {};
    state.roles = Array.isArray(data.roles) ? data.roles : [];
    state.isSuperuser = data.is_superuser === true;
    state.permissions = Array.isArray(data.permissions) ? data.permissions : [];
    state.knownPermissions = Array.isArray(data.known_permissions) ? data.known_permissions : [];
    delete window.__ADMIN_PERMISSION_SET__;
    delete window.__ADMIN_KNOWN_PERMISSION_SET__;
    return state;
}

function bootAdminShell(vm) {
    return Promise.allSettled([
        adminCachedGet(ADMIN_MENU_URL, applyAdminMenuState),
        adminCachedGet(ADMIN_RBAC_URL, applyAdminRbacState)
    ])
        .finally(() => {
            vm.$mount('#app').auto_reload();
        });
}

Vue.component('admin-theme-switcher', {
    template: `
<div class="admin-theme-switcher">
  <button
    type="button"
    class="admin-theme-button"
    :class="'theme-' + theme"
    :title="titleFor(theme)"
    :aria-label="titleFor(theme)"
    @click="toggleTheme"
  ><i :class="iconFor(theme)"></i></button>
</div>`,
    data() {
        return {
            theme: 'light'
        };
    },
    created() {
        let t = typeof localStorage !== 'undefined' ? localStorage.getItem('switon.admin.theme') : null;
        const resolved = resolveAdminTheme(t);
        if (t !== resolved) {
            try {
                localStorage.setItem('switon.admin.theme', resolved);
            } catch (e) {
                /* ignore */
            }
        }
        this.theme = resolved;
    },
    mounted() {
        this.apply();
    },
    methods: {
        titleFor(id) {
            return id === 'dark' ? 'Switch to light theme' : 'Switch to dark theme';
        },
        iconFor(id) {
            return id === 'dark' ? 'fa fa-moon-o' : 'fa fa-sun-o';
        },
        toggleTheme() {
            this.theme = this.theme === 'dark' ? 'light' : 'dark';
            try {
                localStorage.setItem('switon.admin.theme', this.theme);
            } catch (e) {
                /* ignore */
            }
            this.apply();
        },
        apply() {
            document.documentElement.setAttribute('data-admin-theme', resolveAdminTheme(this.theme));
        }
    }
});

Vue.component('m-menu', {
    template: `
<el-menu :default-active="active" class="el-menu-vertical-demo" :unique-opened="true" :collapse="$root.sidebarCollapsed">
    <template v-for="group in groups">
        <el-submenu :index="group.group_name">
            <template slot="title">
                <i :class="group.icon"></i>
                <span>{{group.group_name}}</span>
            </template>
            <template v-for="item in group.items ">
                <el-menu-item :index="baseURL+item.url" @click="click(item)">
                    <i :class="item.icon"></i>
                    <span slot="title">
                        <el-link :href="baseURL+item.url" :underline="false">{{item.item_name}}</el-link>
                    </span>
                </el-menu-item>
            </template>
        </el-submenu>
     </template>
</el-menu>`,
    created() {
        const state = adminShellState();
        if (Array.isArray(state.menuGroups)) {
            this.applyGroups(state.menuGroups);
            return;
        }
        this.ajaxGet(ADMIN_MENU_URL, (res) => {
            this.applyGroups(res);
        });
    },
    data() {
        return {
            baseURL: window.BASE_URL,
            active: location.pathname,
            groups: []
        };
    },
    methods: {
        applyGroups(groups) {
            this.groups = Array.isArray(groups) ? groups : [];

            for (let group of this.groups) {
                for (let item of group.items) {
                    if (item.url === this.active) {
                        document.title = ' ' + group.group_name + ' - ' + item.item_name;
                        return;
                    }
                }
            }
        },
        click(item) {
            let tabs = getStoredTabs();
            delete tabs[item.url];
            tabs[item.url] = {name: item.item_name, url: item.url};
            saveStoredTabs(tabs);
        }
    }
});

Vue.component('my-menu-tabs', {
    template: `
<div class="admin-workbar">
    <el-tabs @tab-remove="remove" @tab-click="click" class="my-menu-tabs" v-model="tab">
        <el-tab-pane :label="adminT('layout.home')" name="/"></el-tab-pane>
        <el-tab-pane v-for="tab in tabs" :label="tab.name" closable :name="tab.url" :key="tab.url"></el-tab-pane>
    </el-tabs>
    <div class="admin-workbar-tools">
        <m-axios-cache-switcher></m-axios-cache-switcher>
        <admin-theme-switcher></admin-theme-switcher>
        <el-dropdown size="small" trigger="click" class="admin-user-menu">
            <span class="admin-avatar" :title="userMenuTitle">
                <span class="admin-avatar__text">{{userInitial}}</span>
                <span v-if="messageCount > 0" class="admin-avatar__badge"></span>
            </span>
            <m-my-menu></m-my-menu>
        </el-dropdown>
    </div>
    </div>`,
    data() {
        const state = adminShellState();
        return {
            tabs: getStoredTabs(),
            tab: location.pathname.substring(window.BASE_URL.length),
            userName: state.userName || 'User',
            messageCount: Number(state.messageCount || 0)
        };
    },
    mounted() {
        this._adminSignalCountListener = (event) => {
            const detail = event && event.detail ? event.detail : {};
            this.messageCount = Number(detail.count || 0);
        };
        window.addEventListener('admin-signal-count-change', this._adminSignalCountListener);
    },
    beforeDestroy() {
        if (this._adminSignalCountListener) {
            window.removeEventListener('admin-signal-count-change', this._adminSignalCountListener);
        }
    },
    computed: {
        userInitial() {
            const value = (this.userName || '').trim();
            return value ? value.charAt(0).toUpperCase() : 'U';
        },
        userMenuTitle() {
            return this.userName;
        }
    },
    methods: {
        remove(name) {
            let tabs = getStoredTabs();
            delete tabs[name];
            this.$delete(this.tabs, name);
            saveStoredTabs(tabs);
        },

        click(tab) {
            let url = '';
            if (tab.name !== '/') {
                const tabs = getStoredTabs();
                const entry = tabs[tab.name];
                if (!entry || !entry.url) {
                    return;
                }
                url = entry.url;
            }
            window.location.href = window.BASE_URL + url;
        }
    }
});

Vue.component('m-axios-cache-switcher', {
    template: `
<div>
    <el-link
        :underline="false"
        @click="toggle(!enabled)"
        :title="enabled ? adminT('layout.cache_enabled') : adminT('layout.cache_disabled')"
        :style="{ color: enabled ? '#67C23A' : '#909399', fontSize: '16px' }"
    >
        <i class="fa fa-database"></i>
    </el-link>
</div>`,
    data() {
        return {
            enabled: window.__axiosCacheEnabled === true
        };
    },
    methods: {
        clearAxiosCache() {
            const keys = [];
            for (let i = 0; i < sessionStorage.length; i++) {
                const key = sessionStorage.key(i);
                if (key && key.indexOf('axios.cache.') === 0) {
                    keys.push(key);
                }
            }
            keys.forEach((key) => sessionStorage.removeItem(key));
        },
        toggle(nextEnabled) {
            window.__axiosCacheEnabled = nextEnabled;
            localStorage.setItem('axios.cache.enabled', nextEnabled ? '1' : '0');
            if (!nextEnabled) {
                this.clearAxiosCache();
            }
            this.enabled = nextEnabled;
        }
    }
});

Vue.component('m-system-time', {
    template: '<span class="left" :title="diff" :style="{backgroundColor: bgColor}" style="cursor:pointer" v-show="diff!==null&&time!==null" @click="update">{{time}}</span>',
    data() {
        return {
            diff: null,
            time: null,
            key: 'system-time.diff',
            timer: null
        };
    },
    computed: {
        bgColor() {
            return Math.abs(this.diff) >= 1 ? 'red' : 'transparent';
        }
    },
    created() {
        this.update();
        this.timer = setInterval(() => {
            if (this.diff !== null) {
                this.time = (moment().subtract(this.diff, 'seconds').format('YYYY-MM-DD hh:mm:ss'));
            }
        }, 1000);
    },
    beforeDestroy() {
        if (this.timer !== null) {
            clearInterval(this.timer);
            this.timer = null;
        }
    },
    methods: {
        update() {
            axios.get('/time/current?t=' + Date.now()).then((res) => {
                if (isApiSuccessCode(res.data.code)) {
                    this.diff = Math.round(Date.now() - res.data.data.timestamp * 1000) / 1000;
                }
            });
        }
    }
});

Vue.component('m-my-menu', {
    template: `
<el-dropdown-menu slot="dropdown" class="admin-user-dropdown">
    <el-dropdown-item disabled>{{userName}}</el-dropdown-item>
    <el-dropdown-item>
        <el-link :href="BASE_URL + '/admin/login-log/latest'" target="_self">{{adminT('layout.recent_login')}}</el-link>
    </el-dropdown-item>
    <el-dropdown-item>
        <el-link :href="BASE_URL + '/admin/action-log/latest'" target="_self">{{adminT('layout.recent_actions')}}</el-link>
    </el-dropdown-item>
    <el-dropdown-item>
        <el-link :href="BASE_URL + '/admin/password/change'" target="_self">{{adminT('auth.password_change')}}</el-link>
    </el-dropdown-item>
    <el-dropdown-item>
        <el-link :href="BASE_URL + '/admin/session/logout'" target="_self">{{adminT('layout.logout')}}</el-link>
    </el-dropdown-item>
</el-dropdown-menu>`,
    data() {
        const state = adminShellState();
        return {
            userName: state.userName || 'User'
        };
    }
});

Vue.component('inspector-links', {
    template: `
    <div class="inspector-panel" :style="panelStyle">
        <div class="inspector-content">
            <div class="inspector-toggle" 
                 @click="toggleExpanded"
                 @mousedown="startDrag"
                 :title="'Inspector Links: ' + links.length + ' requests'">
                {{ expanded ? '×' : '⚙' }}
            </div>
            <div v-if="expanded" class="inspector-links">
                <a v-for="link in links" 
                   :key="link.index"
                   :href="link.url" 
                   :title="'#' + link.index + ' ' + getPath(link.requestUrl)"
                   target="_blank"
                   :class="getLinkClass(link)">
                    {{link.index}}
                </a>
            </div>
        </div>
    </div>`,
    data() {
        const savedExpanded = localStorage.getItem('inspector-panel-expanded');
        const savedPosX = localStorage.getItem('inspector-panel-pos-x');
        const savedPosY = localStorage.getItem('inspector-panel-pos-y');
        return {
            links: Array.isArray(window.inspectorLinks) ? [...window.inspectorLinks] : [],
            expanded: savedExpanded !== null ? savedExpanded === 'true' : true,
            posX: savedPosX ? parseInt(savedPosX) : null,
            posY: savedPosY ? parseInt(savedPosY) : null,
            isDragging: false
        };
    },
    computed: {
        latestBatchId() {
            if (this.links.length === 0) {
                return null;
            }
            return this.links[this.links.length - 1].batchId;
        },
        panelStyle() {
            if (this.posX !== null && this.posY !== null) {
                return {
                    top: this.posY + 'px',
                    left: this.posX + 'px',
                    transform: 'none'
                };
            }
            return {};
        }
    },
    methods: {
        toggleExpanded() {
            if (!this.isDragging) {
                this.expanded = !this.expanded;
                localStorage.setItem('inspector-panel-expanded', this.expanded.toString());
            }
        },
        startDrag(e) {
            let startX = e.clientX;
            let startY = e.clientY;
            let startPosX = this.posX || (window.innerWidth / 2);
            let startPosY = this.posY || 5;
            let moved = false;

            const onMove = (evt) => {
                const deltaX = evt.clientX - startX;
                const deltaY = evt.clientY - startY;

                if (Math.abs(deltaX) > 5 || Math.abs(deltaY) > 5) {
                    moved = true;
                    this.isDragging = true;
                    this.posX = Math.max(0, Math.min(window.innerWidth - 100, startPosX + deltaX));
                    this.posY = Math.max(0, Math.min(window.innerHeight - 100, startPosY + deltaY));
                }
            };

            const onUp = () => {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);

                if (moved) {
                    localStorage.setItem('inspector-panel-pos-x', this.posX.toString());
                    localStorage.setItem('inspector-panel-pos-y', this.posY.toString());
                }

                setTimeout(() => {
                    this.isDragging = false;
                }, 100);
            };

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
            e.stopPropagation();
        },
        getLinkClass(link) {
            const latestBatchId = this.latestBatchId;
            const isRecent = latestBatchId !== null && link.batchId === latestBatchId;
            const method = (link.method || 'GET').toLowerCase();
            let classes = ['inspector-link', 'inspector-link-' + method];
            if (isRecent) {
                classes.push('inspector-link-recent');
            }
            return classes.join(' ');
        },
        getPath(url) {
            try {
                const urlObj = new URL(url, window.location.origin);
                return urlObj.pathname;
            } catch (e) {
                return url;
            }
        }
    },
    mounted() {
        window.inspectorComponent = this;
        if (Array.isArray(window.inspectorLinks)) {
            this.links = [...window.inspectorLinks];
        }
    }
});
