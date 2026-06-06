Vue.component('files-tab', {
    props: ['files', 'displayFiles'],
    data: function () {
        return {applicationOnly: true, pathFilter: ''};
    },
    computed: {
        filtered: function () {
            var files = this.displayFiles || this.files || [];
            var f = this.pathFilter ? this.pathFilter.toLowerCase() : '';
            var appOnly = this.applicationOnly;
            var vendorRe = /(?:^|\/)(?:vendor|packages)\//;
            var out = [];
            for (var i = 0; i < files.length; i++) {
                var dp = files[i].replace(/\\/g, '/');
                if (appOnly && vendorRe.test(dp)) continue;
                if (f && dp.toLowerCase().indexOf(f) === -1) continue;
                out.push(dp);
            }
            return out;
        }
    },
    watch: {
        applicationOnly: function () {
            this.pathFilter = '';
        }
    },
    template:
        '<div class="tab-pane" id="files">' +
        '<h4>IncludedFiles({{filtered.length}}) <small>' +
        '<label><input type="checkbox" v-model="applicationOnly">Ignore Framework Files</label>' +
        '<filter-input v-model="pathFilter" label="Path"></filter-input>' +
        '</small></h4>' +
        '<table class="table table-bordered table-condensed">' +
        '<thead><tr><td style="width:5%">#</td><td>file</td></tr></thead>' +
        '<tbody><tr v-for="(file,index) in filtered">' +
        '<td>{{index+1}}</td><td>{{file}}</td>' +
        '</tr></tbody>' +
        '</table>' +
        '</div>'
});
