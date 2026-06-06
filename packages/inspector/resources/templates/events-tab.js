Vue.component('events-tab', {
    props: ['events'],
    data: function () {
        return {filter: ''};
    },
    computed: {
        filtered: function () {
            var list = this.events.list || [];
            if (!this.filter) return list;
            var f = this.filter.toLowerCase();
            return list.filter(function (e) {
                return (e.event || '').toLowerCase().indexOf(f) !== -1;
            });
        }
    },
    template:
        '<div class="tab-pane" id="events">' +
        '<h4>Events({{filtered.length}}) <small>' +
        '<filter-input v-model="filter" label="Event"></filter-input>' +
        '</small></h4>' +
        '<table class="table table-bordered table-condensed">' +
        '<thead><tr><td style="width:5%">#</td><td style="width:30%">Event</td><td>Listeners</td></tr></thead>' +
        '<tbody><tr v-for="(event,index) in filtered">' +
        '<td>{{index+1}}</td><td>{{event.event}}</td>' +
        '<td>' +
        '<div v-for="listener in event.listeners" :class="listener.type===\'observability\'?\'text-muted\':\'\'">' +
        '<small>{{listener.type===\'business\'?\'\uD83C\uDFAF\':\'\uD83D\uDCCA\'}}</small> {{listener.name}}' +
        '</div>' +
        '<span v-if="!event.listeners||event.listeners.length===0" class="text-muted">No listeners</span>' +
        '</td>' +
        '</tr></tbody>' +
        '</table>' +
        '</div>'
});
