Vue.component('renderer-tab', {
    mixins: [jsonMixin],
    props: ['renderer'],
    template:
        '<div class="tab-pane" id="renderer">' +
        '<h4>Rendered Templates({{renderer.length}})</h4>' +
        '<table class="table table-bordered table-condensed">' +
        '<thead><tr><td style="width:5%">#</td><td style="width:20%">file</td><td>vars</td></tr></thead>' +
        '<tbody><tr v-for="(item,index) in renderer">' +
        '<td>{{index}}</td>' +
        '<td :title="item.file">{{item.file_display || item.file}}</td>' +
        '<td><pre :title="jsonStringify(item.vars)">{{item.vars | json(4)}}</pre></td>' +
        '</tr></tbody>' +
        '</table>' +
        '</div>'
});
