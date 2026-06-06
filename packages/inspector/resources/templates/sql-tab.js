Vue.component('sql-tab', {
    mixins: [jsonMixin],
    props: ['sql'],
    data: function () {
        return {executed: true, filter: ''};
    },
    computed: {
        filtered: function () {
            var f = this.filter.toLowerCase();
            if (this.executed) {
                var list = this.sql.executed || [];
                return f ? list.filter(function (i) {
                    return i.emulated.toLowerCase().indexOf(f) !== -1;
                }) : list;
            }
            var out = [], prep = this.sql.prepared || {};
            for (var sql in prep) {
                if (!f || sql.toLowerCase().indexOf(f) !== -1) out.push({sql: sql, count: prep[sql]});
            }
            return out;
        }
    },
    watch: {
        executed: function () {
            this.filter = '';
        }
    },
    methods: {
        fmtSql: function (s) {
            return s.replace(/\[(\w+)\]/g, '\x60$1\x60');
        }
    },
    template:
        '<div class="tab-pane" id="sql">' +
        '<h4>SQL({{filtered.length}}) <small>' +
        '<label><input type="checkbox" v-model="executed"> Executed</label>' +
        '<filter-input v-model="filter" label="SQL"></filter-input>' +
        '</small></h4>' +
        '<div v-if="executed"><table class="table table-bordered table-condensed">' +
        '<thead><tr><td style="width:50px">#</td><td>executed sql</td><td style="width:50px">rows</td><td>elapsed</td></tr></thead>' +
        '<tbody><tr v-for="(item,index) in filtered" :title="jsonStringify(item)">' +
        '<td>{{index+1}}</td><td>{{fmtSql(item.emulated)}}</td><td>{{item.row_count}}</td><td>{{item.elapsed}}</td>' +
        '</tr></tbody>' +
        '</table></div>' +
        '<div v-else><table class="table table-bordered table-condensed">' +
        '<thead><tr><td style="width:50px">#</td><td>prepared sql</td><td style="width:50px">count</td></tr></thead>' +
        '<tbody><tr v-for="(item,index) in filtered">' +
        '<td>{{index+1}}</td><td>{{fmtSql(item.sql)}}</td><td>{{item.count}}</td>' +
        '</tr></tbody>' +
        '</table></div>' +
        '</div>'
});
