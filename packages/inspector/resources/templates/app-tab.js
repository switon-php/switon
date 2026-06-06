Vue.component('app-tab', {
    props: ['app'],
    template:
        '<div class="tab-pane" id="application">' +
        '<ul class="nav nav-tabs" style="margin-bottom:10px">' +
        '<li class="active"><a href="#app-info" data-toggle="tab">Application</a></li>' +
        '<li><a href="#app-env" data-toggle="tab">Environment</a></li>' +
        '</ul>' +
        '<div class="tab-content">' +
        '<div class="tab-pane active" id="app-info"><table class="table table-bordered table-condensed"><tbody>' +
        '<tr><td style="width:5%">1</td><td style="width:30%">id</td><td>{{app.id}}</td></tr>' +
        '<tr><td>2</td><td>name</td><td>{{app.name}}</td></tr>' +
        '<tr><td>3</td><td>version</td><td>{{app.version}}</td></tr>' +
        '<tr><td>4</td><td>timezone</td><td>{{app.timezone}}</td></tr>' +
        '</tbody></table></div>' +
        '<div class="tab-pane" id="app-env"><table class="table table-bordered table-condensed"><tbody>' +
        '<tr><td style="width:5%">1</td><td style="width:30%">env</td><td><strong>{{app.env}}</strong></td></tr>' +
        '<tr><td>2</td><td>debug</td><td><strong>{{app.debug}}</strong></td></tr>' +
        '</tbody></table></div>' +
        '</div>' +
        '</div>'
});
