Vue.component('event-mappings-tab', {
    props: ['eventMappings'],
    data: function () {
        return {categoryFilter: ''};
    },
    computed: {
        filtered: function () {
            var categories = this.eventMappings.categories || {};
            var levels = this.eventMappings.levels || {};
            var out = [];
            if (categories._keys && Array.isArray(categories._keys)) {
                for (var i = 0; i < categories._keys.length; i++) {
                    var ec = categories._keys[i];
                    out.push({eventClass: ec, category: ec, level: 'debug'});
                }
            } else {
                for (var ec in categories) {
                    if (!Object.prototype.hasOwnProperty.call(categories, ec)) continue;
                    out.push({eventClass: ec, category: categories[ec], level: (levels[ec]) || 'debug'});
                }
            }
            if (this.categoryFilter) {
                var f = this.categoryFilter.toLowerCase();
                out = out.filter(function (m) {
                    return (m.category || '').toLowerCase().indexOf(f) !== -1;
                });
            }
            return out;
        }
    },
    template:
        '<div class="tab-pane" id="event_mappings">' +
        '<h4>Event Category Mappings ({{filtered.length}}) <small>' +
        '<filter-input v-model="categoryFilter" label="Category"></filter-input>' +
        '</small></h4>' +
        '<p class="text-muted">Event class name &#8594; Simplified category &#8594; Log level</p>' +
        '<table class="table table-bordered table-condensed table-striped">' +
        '<thead><tr><td style="width:5%">#</td><td style="width:35%">Category</td><td style="width:50%">Event Class</td><td style="width:10%">Level</td></tr></thead>' +
        '<tbody><tr v-for="(item,index) in filtered">' +
        '<td>{{index+1}}</td><td><strong>{{item.category}}</strong></td><td><code>{{item.eventClass}}</code></td>' +
        '<td><span :class="{\'label\':true,\'label-default\':item.level===\'debug\',\'label-info\':item.level===\'info\',\'label-warning\':item.level===\'warning\',\'label-danger\':item.level===\'error\'}">{{item.level}}</span></td>' +
        '</tr></tbody>' +
        '</table>' +
        '</div>'
});
