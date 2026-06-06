Vue.component('router-tab', {
    props: ['routes'],
    data: function () {
        return {methodFilter: '', patternFilter: '', handlerFilter: ''};
    },
    computed: {
        filtered: function () {
            var r = this.routes || [];
            if (this.methodFilter) {
                var m = this.methodFilter;
                r = r.filter(function (i) {
                    return i.method === m;
                });
            }
            if (this.patternFilter) {
                var f = this.patternFilter.toLowerCase();
                r = r.filter(function (i) {
                    return i.pattern.toLowerCase().indexOf(f) !== -1;
                });
            } else if (this.handlerFilter) {
                var f = this.handlerFilter.toLowerCase();
                r = r.filter(function (i) {
                    return i.handler.toLowerCase().indexOf(f) !== -1;
                });
            }
            return r;
        }
    },
    watch: {
        patternFilter: function (v) {
            if (v) this.handlerFilter = '';
        },
        handlerFilter: function (v) {
            if (v) this.patternFilter = '';
        }
    },
    template:
        '<div class="tab-pane" id="router">' +
        '<h4>Router({{filtered.length}}) <small>' +
        '<label style="margin-left:10px">Method: ' +
        '<select v-model="methodFilter"><option value="">All</option>' +
        '<option value="GET">GET</option><option value="POST">POST</option>' +
        '<option value="PUT">PUT</option><option value="DELETE">DELETE</option><option value="PATCH">PATCH</option>' +
        '</select>' +
        '</label>' +
        '<filter-input v-model="patternFilter" label="Pattern"></filter-input>' +
        '<filter-input v-model="handlerFilter" label="Handler"></filter-input>' +
        '</small></h4>' +
        '<table class="table table-bordered table-condensed">' +
        '<thead><tr><td style="width:5%">#</td><td>method</td><td>pattern</td><td>handler</td></tr></thead>' +
        '<tbody><tr v-for="(route,index) in filtered">' +
        '<td>{{index+1}}</td><td>{{route.method}}</td><td>{{route.pattern}}</td><td>{{route.handler}}</td>' +
        '</tr></tbody>' +
        '</table>' +
        '</div>'
});
