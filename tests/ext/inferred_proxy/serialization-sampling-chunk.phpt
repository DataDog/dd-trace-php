--TEST--
Inferred spans use the sampling snapshot of their serialized trace chunk
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_SAMPLE_RATE=0
DD_SPAN_SAMPLING_RULES=[{"name":"keep.root","sample_rate":1}]
DD_TRACE_STATS_COMPUTATION_ENABLED=0
DD_TRACE_INFERRED_PROXY_SERVICES_ENABLED=1
HTTP_X_DD_PROXY=aws-apigateway
HTTP_X_DD_PROXY_REQUEST_TIME_MS=100
HTTP_X_DD_PROXY_PATH=/test
HTTP_X_DD_PROXY_HTTPMETHOD=GET
HTTP_X_DD_PROXY_DOMAIN_NAME=example.com
--GET--
test=1
--FILE--
<?php

class ChangeSamplingDuringSerialization {
    public function __destruct() {
        ini_set('datadog.trace.sample_rate', '1');
    }
}

$root = DDTrace\start_span();
$root->name = 'keep.root';
$root->attributes['http.status_code'] = new ChangeSamplingDuringSerialization();
DDTrace\close_span();

foreach (dd_trace_serialize_closed_spans() as $span) {
    echo $span['name'], "\n";
    if (isset($span['attributes']['_dd.span_sampling.mechanism'])) {
        echo 'single span: ', $span['attributes']['_dd.span_sampling.mechanism'], "\n";
    }
    if (isset($span['sampling_priority'])) {
        echo 'trace priority: ', $span['sampling_priority'], "\n";
    }
}

?>
--EXPECT--
keep.root
single span: 8
aws.apigateway
trace priority: -1
