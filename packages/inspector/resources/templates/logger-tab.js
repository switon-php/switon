Vue.component('logger-tab', {
    props: ['logger'],
    data: function () {
        return {currentLevel: this.logger.default_level, messageFilter: ''};
    },
    computed: {
        filtered: function () {
            var self = this;
            var logs = this.logger.logs.filter(function (l) {
                return self.logger.levels[l.level] <= self.currentLevel;
            });
            if (this.messageFilter) {
                var f = this.messageFilter.toLowerCase();
                logs = logs.filter(function (l) {
                    return l.message.toLowerCase().indexOf(f) !== -1 || l.category.toLowerCase().indexOf(f) !== -1;
                });
            }
            return logs;
        }
    },
    watch: {
        currentLevel: function () {
            this.messageFilter = '';
        }
    },
    methods: {
        levelClass: function (level) {
            var m = {
                emergency: 'bg-danger',
                alert: 'bg-danger',
                critical: 'bg-danger',
                error: 'bg-danger',
                warning: 'bg-warning',
                notice: 'bg-warning',
                info: 'bg-info',
                debug: ''
            };
            return m[level] || '';
        },
        jsonPretty: function (str) {
            if (!str || str === '') return '';
            if (str[0] === '{' || str[0] === '[') {
                try {
                    return JSON.stringify(JSON.parse(str), null, 4);
                } catch (e) {
                    return str;
                }
            }
            return str;
        }
    },
    template:
        '<div class="tab-pane" id="logger">' +
        '<h4>Log Messages({{filtered.length}}) <small>' +
        '<select v-model="currentLevel">' +
        '<option v-for="(intLevel,strLevel) in logger.levels" :value="intLevel">{{strLevel}}</option>' +
        '</select>' +
        '<filter-input v-model="messageFilter" label="Message"></filter-input>' +
        '</small></h4>' +
        '<table class="table table-bordered table-condensed">' +
        '<thead><tr><td>#</td><td>time</td><td>level</td><td>category</td><td>location</td><td>message</td></tr></thead>' +
        '<tbody><tr v-for="(log,index) in filtered">' +
        '<td>{{index+1}}</td><td>{{log.time}}</td><td>{{log.level}}</td><td>{{log.category}}</td>' +
        '<td>{{log.file}}:{{log.line}}</td>' +
        '<td :title="jsonPretty(log.message)" :class="levelClass(log.level)">{{log.message}}</td>' +
        '</tr></tbody>' +
        '</table>' +
        '</div>'
});
