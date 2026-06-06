const adminMessageSource = Object.freeze(window.__SWITON_ADMIN_MESSAGES__ || {});

function adminT(key, bind) {
    var message = Object.prototype.hasOwnProperty.call(adminMessageSource, key) ? adminMessageSource[key] : key;
    if (!bind) {
        return message;
    }

    return message.replace(/\{([^}]+)\}/g, function (_, name) {
        return Object.prototype.hasOwnProperty.call(bind, name) ? String(bind[name]) : '{' + name + '}';
    });
}

window.AdminMessages = adminMessageSource;
window.adminT = adminT;

/**
 * Shared admin UI defaults.
 *
 * Optional runtime overrides before ui.js:
 * - window.__SWITON_ADMIN_TIME_ZONE__
 * - window.__SWITON_ADMIN_WEEK_START__
 */
window.AdminUiDefaults = Object.freeze({
    /**
     * Query param names whose empty string is meaningful (e.g. filter「全部」) and must sync in the URL
     * and hydrate from `?name=`. Safe to push at runtime before the list page mounts; default entries belong here.
     */
    keepEmptyQueryKeys: ['derived'],
    /** IANA zone for calendar shortcuts; null = browser local (independent of weekStartsOn). */
    timeZone: null,
    /** First day of week for「本周」: 0=Sunday … 6=Saturday (same as Date.getDay()). Default 1=Monday. */
    weekStartsOn: 1,
    primaryKeyResolver: null,
    labels: Object.freeze({
        id: 'ID',
        admin_id: adminT('label.admin_id'),
        admin_name: adminT('label.admin_name'),
        created_at: adminT('label.created_at'),
        updated_at: adminT('label.updated_at'),
        created_by: adminT('label.created_by'),
        updated_by: adminT('label.updated_by'),
        data: adminT('label.data'),
        client_ip: adminT('label.client_ip'),
        display_order: adminT('label.display_order'),
        display_name: adminT('label.display_name'),
        role_code: adminT('label.role_code'),
        role_name: adminT('label.role_name'),
        password: adminT('auth.password'),
        white_ip: adminT('label.white_ip'),
        status: adminT('label.status'),
        email: adminT('auth.email'),
        tag: adminT('label.tag'),
        icon: adminT('label.icon'),
        path: adminT('label.path'),
        builtin: adminT('label.builtin'),
        enabled: adminT('label.enabled'),
        type: adminT('label.type'),
        method: adminT('label.method'),
        handler: adminT('label.handler'),
        url: adminT('label.url'),
        client_udid: adminT('label.client_udid')
    })
});
