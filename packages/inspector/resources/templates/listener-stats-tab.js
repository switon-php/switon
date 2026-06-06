Vue.component('listener-stats-tab', {
    props: ['stats'],
    data: function () {
        return {type: 'business', nameFilter: ''};
    },
    computed: {
        business: function () {
            return this.stats && this.stats.business || {};
        },
        observability: function () {
            return this.stats && this.stats.observability || {};
        },
        wildcards: function () {
            return this.stats && this.stats.wildcards || [];
        },
        filtered: function () {
            var out = [], f = this.nameFilter.toLowerCase();
            if (this.type === 'wildcards') {
                for (var i = 0; i < this.wildcards.length; i++) {
                    var p = this.wildcards[i];
                    if (!f || p.name.toLowerCase().indexOf(f) !== -1) out.push({
                        name: p.name,
                        value: p.type,
                        muted: p.type === 'observability'
                    });
                }
            } else {
                var map = this.type === 'business' ? this.business : this.observability;
                for (var name in map) {
                    if (!f || name.toLowerCase().indexOf(f) !== -1) out.push({
                        name: name,
                        value: map[name],
                        muted: map[name] === 0
                    });
                }
            }
            return out;
        }
    },
    watch: {
        type: function () {
            this.nameFilter = '';
        }
    },
    template:
        '<div class="tab-pane" id="listener_stats">' +
        '<h4>Listeners ({{filtered.length}}) <small>' +
        '<label><input type="radio" v-model="type" value="business"> Business ({{Object.values(business).filter(function(c){return c>0}).length}}/{{Object.keys(business).length}})</label>' +
        '<label><input type="radio" v-model="type" value="observability"> Observability ({{Object.values(observability).filter(function(c){return c>0}).length}}/{{Object.keys(observability).length}})</label>' +
        '<label><input type="radio" v-model="type" value="wildcards"> Wildcards ({{wildcards.length}})</label>' +
        '<filter-input v-model="nameFilter" label="Name"></filter-input>' +
        '</small></h4>' +
        '<table class="table table-bordered table-condensed">' +
        '<thead><tr><td style="width:5%">#</td><td>Listener</td><td style="width:15%">{{type===\'wildcards\'?\'Type\':\'Invocations\'}}</td></tr></thead>' +
        '<tbody><tr v-for="(item,index) in filtered" :class="item.muted?\'text-muted\':\'\'">' +
        '<td>{{index+1}}</td><td>{{item.name}}</td><td>{{item.value}}</td>' +
        '</tr></tbody>' +
        '</table>' +
        '</div>'
});
