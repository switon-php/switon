Vue.component('dependencies-tab', {
    mixins: [jsonMixin],
    props: ['dependencies'],
    data: function () {
        return {nonSingletonOnly: false, ignoreController: false, idFilter: ''};
    },
    computed: {
        filtered: function () {
            var values = Object.values(this.dependencies);
            var out = [];
            for (var id in this.dependencies) {
                var item = this.dependencies[id];
                var instanceCount = (new Set(values.filter(function (d) {
                    return d.class === item.class;
                }).map(function (d) {
                    return d.object_id;
                }))).size;
                if (this.nonSingletonOnly && (instanceCount <= 1 || typeof item.properties === 'string')) continue;
                if (this.ignoreController && item.class.indexOf('Controllers') !== -1) continue;
                if (this.idFilter && id.toLowerCase().indexOf(this.idFilter.toLowerCase()) === -1) continue;
                out.push({
                    id: id,
                    object_id: item.object_id,
                    class: item.class,
                    instance_count: instanceCount,
                    properties: item.properties
                });
            }
            return out;
        }
    },
    watch: {
        nonSingletonOnly: function () {
            this.idFilter = '';
        },
        ignoreController: function () {
            this.idFilter = '';
        }
    },
    template:
        '<div class="tab-pane" id="dependencies">' +
        '<h4>Dependencies({{filtered.length}}) <small>' +
        '<label><input type="checkbox" v-model="nonSingletonOnly">Non Singleton Only</label>' +
        '<label><input type="checkbox" v-model="ignoreController">Ignore Controllers</label>' +
        '<filter-input v-model="idFilter" label="ID"></filter-input>' +
        '</small></h4>' +
        '<table class="table table-bordered table-condensed">' +
        '<thead><tr><td width="5%">#</td><td width="5%">object_id</td><td width="40%">id</td><td>properties</td></tr></thead>' +
        '<tbody><tr v-for="(dep,index) in filtered" :class="{danger:dep.instance_count>1&&typeof dep.properties!==\'string\',info:typeof dep.properties===\'string\'}">' +
        '<td>{{index+1}}</td>' +
        '<td :title="\'instance_count: \'+dep.instance_count">#{{dep.object_id}}</td>' +
        '<td :title="dep.class"><pre>{{dep.id}}<div v-if="!dep.id.startsWith(dep.class)">=> {{dep.class}}</div></pre></td>' +
        '<td :title="jsonStringify(dep.properties)"><pre>{{dep.properties | json(4)}}</pre></td>' +
        '</tr></tbody>' +
        '</table>' +
        '</div>'
});
