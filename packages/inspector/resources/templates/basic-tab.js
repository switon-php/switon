Vue.component('basic-tab', {
    props: ['basic', 'sizeAnalysis'],
    template:
        '<div class="tab-pane" id="basic">' +
        '<ul class="nav nav-tabs" style="margin-bottom:10px">' +
        '<li class="active"><a href="#basic-common" data-toggle="tab">Common</a></li>' +
        '<li><a href="#basic-request" data-toggle="tab">Request</a></li>' +
        '<li><a href="#basic-performance" data-toggle="tab">Performance</a></li>' +
        '<li><a href="#basic-server" data-toggle="tab">Server</a></li>' +
        '<li><a href="#basic-php" data-toggle="tab">PHP</a></li>' +
        '<li v-if="sizeAnalysis"><a href="#basic-size" data-toggle="tab">Size Analysis</a></li>' +
        '</ul>' +
        '<div class="tab-content">' +
        /* Common */
        '<div class="tab-pane active" id="basic-common"><table class="table table-bordered table-condensed"><tbody>' +
        '<tr><td style="width:5%">1</td><td style="width:30%">handler</td><td><code>{{basic.request.handler}}</code></td></tr>' +
        '<tr><td>2</td><td>request_method</td><td>{{basic.request.method}}</td></tr>' +
        '<tr><td>3</td><td>request_url</td><td>{{basic.request.url}}</td></tr>' +
        '<tr><td>4</td><td>execute_time</td><td><strong>{{basic.performance.execute_time}}</strong></td></tr>' +
        '<tr><td>5</td><td>memory_usage</td><td>{{basic.performance.memory_usage}}</td></tr>' +
        '<tr><td>6</td><td>client_ip</td><td>{{basic.server.client_ip}}</td></tr>' +
        '<tr><td>7</td><td>php_version</td><td>{{basic.php.version}}</td></tr>' +
        '<tr><td>8</td><td>framework_version</td><td>{{basic.php.framework_version}}</td></tr>' +
        '<tr><td>9</td><td>app_version</td><td>{{basic.php.app_version}}</td></tr>' +
        '</tbody></table></div>' +
        /* Request */
        '<div class="tab-pane" id="basic-request"><table class="table table-bordered table-condensed"><tbody>' +
        '<tr><td style="width:5%">1</td><td style="width:30%">handler</td><td><code>{{basic.request.handler}}</code></td></tr>' +
        '<tr><td>2</td><td>method</td><td>{{basic.request.method}}</td></tr>' +
        '<tr><td>3</td><td>url</td><td>{{basic.request.url}}</td></tr>' +
        '<tr><td>4</td><td>query</td><td>{{basic.request.query}}</td></tr>' +
        '</tbody></table></div>' +
        /* Performance */
        '<div class="tab-pane" id="basic-performance"><table class="table table-bordered table-condensed"><tbody>' +
        '<tr><td style="width:5%">1</td><td style="width:30%">execute_time</td><td><strong>{{basic.performance.execute_time}}</strong></td></tr>' +
        '<tr><td>2</td><td>memory_usage</td><td>{{basic.performance.memory_usage}}</td></tr>' +
        '<tr><td>3</td><td>system_time</td><td>{{basic.performance.system_time}}</td></tr>' +
        '</tbody></table></div>' +
        /* Server */
        '<div class="tab-pane" id="basic-server"><table class="table table-bordered table-condensed"><tbody>' +
        '<tr><td style="width:5%">1</td><td style="width:30%">ip</td><td>{{basic.server.ip}}</td></tr>' +
        '<tr><td>2</td><td>client_ip</td><td>{{basic.server.client_ip}}</td></tr>' +
        '<tr><td>3</td><td>software</td><td>{{basic.server.software}}</td></tr>' +
        '</tbody></table></div>' +
        /* PHP */
        '<div class="tab-pane" id="basic-php"><table class="table table-bordered table-condensed"><tbody>' +
        '<tr><td style="width:5%">1</td><td style="width:30%">framework_version</td><td>{{basic.php.framework_version}}</td></tr>' +
        '<tr><td>2</td><td>app_version</td><td>{{basic.php.app_version}}</td></tr>' +
        '<tr><td>3</td><td>version</td><td>{{basic.php.version}}</td></tr>' +
        '<tr><td>4</td><td>sapi</td><td>{{basic.php.sapi}}</td></tr>' +
        '<tr><td>5</td><td>loaded_ini</td><td>{{basic.php.loaded_ini}}</td></tr>' +
        '<tr><td>6</td><td>loaded_extensions</td><td>{{basic.php.loaded_extensions}}</td></tr>' +
        '<tr><td>7</td><td>opcache_enable</td><td><span :class="{warning:basic.php.opcache_enable===\'1\'}">{{basic.php.opcache_enable}}</span></td></tr>' +
        '<tr><td>8</td><td>opcache_enable_cli</td><td><span :class="{warning:basic.php.opcache_enable_cli===\'1\'}">{{basic.php.opcache_enable_cli}}</span></td></tr>' +
        '</tbody></table></div>' +
        /* Size Analysis */
        '<div class="tab-pane" id="basic-size" v-if="sizeAnalysis">' +
        '<h4>Data Size Analysis</h4>' +
        '<div v-if="sizeAnalysis._total">' +
        '<p>Total Size: <strong>{{sizeAnalysis._total.size}} ({{sizeAnalysis._total.bytes.toLocaleString()}} bytes)</strong></p>' +
        '<table class="table table-bordered table-condensed table-striped">' +
        '<thead><tr><th style="width:5%">#</th><th style="width:25%">Section</th><th style="width:15%">Size</th><th style="width:20%">Bytes</th><th style="width:10%">Percentage</th></tr></thead>' +
        '<tbody><tr v-for="(info,name,index) in sizeAnalysis" v-if="name!==\'_total\'">' +
        '<td>{{index+1}}</td><td><strong>{{name}}</strong></td><td>{{info.size}}</td><td>{{info.bytes.toLocaleString()}}</td>' +
        '<td><span :style="{color:info.percentage>20?\'#d9534f\':(info.percentage>10?\'#f0ad4e\':\'#5cb85c\')}">{{info.percentage}}%</span></td>' +
        '</tr></tbody>' +
        '</table>' +
        '</div>' +
        '</div>' +
        '</div>' +
        '</div>'
});
