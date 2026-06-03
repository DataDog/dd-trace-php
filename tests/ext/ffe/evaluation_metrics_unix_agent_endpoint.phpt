--TEST--
FFE evaluation metrics flush over DD_TRACE_AGENT_URL unix domain socket
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: There are no unix sockets on Windows'); ?>
--ENV--
DD_TRACE_AGENT_URL=unix:///tmp/ddtrace-ffe-metrics-test.socket
DD_METRICS_OTEL_ENABLED=true
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
--INI--
datadog.trace.agent_test_session_token=ffe/evaluation_metrics_unix_agent_endpoint
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';

function show($label, $value) {
    echo $label . '=' . json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
}

function header_value($request, $name) {
    foreach ($request['headers'] ?? [] as $headerName => $headerValue) {
        if (strcasecmp($headerName, $name) == 0) {
            return $headerValue;
        }
    }
    return null;
}

$rr = new RequestReplayer();
$rr->flushInterval = 10000;
$rr->maxIteration = 100;
$rr->clearDumpedData();

$proxy = RequestReplayer::launchUnixProxy(str_replace("unix://", "", getenv("DD_TRACE_AGENT_URL")));

show('recorded', \DDTrace\Internal\record_ffe_evaluation_metric(
    'unix.metric.flag',
    'blue',
    'SPLIT',
    null,
    'allocation-unix'
));
show('flushed', \DDTrace\Internal\flush_ffe_evaluation_metrics());

$request = $rr->waitForRequest(function ($request) {
    return isset($request['uri']) && $request['uri'] === '/v1/metrics';
});

show('metrics_uri', $request['uri'] ?? null);
show('content_type', header_value($request, 'Content-Type'));
?>
--CLEAN--
<?php
@unlink("/tmp/ddtrace-ffe-metrics-test.socket");
?>
--EXPECT--
recorded=true
flushed=true
metrics_uri="/v1/metrics"
content_type="application/x-protobuf"
