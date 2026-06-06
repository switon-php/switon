Vue.component('providers-tab', {
    props: ['providers'],
    template:
        '<div class="tab-pane" id="providers">' +
        '<h4>Service Providers ({{Object.keys(providers).length}})</h4>' +
        '<kv-table :items="providers"><template slot-scope="s"><code>{{s.value}}</code></template></kv-table>' +
        '</div>'
});
