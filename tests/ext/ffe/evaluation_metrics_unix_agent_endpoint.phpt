--TEST--
FFE evaluation metrics flush over DD_TRACE_AGENT_URL unix domain socket
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: There are no unix sockets on Windows'); ?>
--ENV--
DD_TRACE_AGENT_URL=unix:///tmp/ddtrace-ffe-metrics-test.socket
DD_METRICS_OTEL_ENABLED=true
DD_TRACE_SIDECAR_TRACE_SENDER=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
--INI--
datadog.trace.agent_test_session_token=ffe/evaluation_metrics_unix_agent_endpoint
pcre.jit=0
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';

function show($label, $value) {
    echo $label . '=' . json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
}

function wait_for_proxy_request($path) {
    for ($i = 0; $i < 100; $i++) {
        usleep(10000);
        $log = @file_get_contents($path);
        if (is_string($log) && preg_match('/^POST \/v1\/metrics HTTP\/1\.1\r?\n.*?\r?\n\r?\n/ms', $log, $matches)) {
            return $matches[0];
        }
    }

    throw new Exception('wait for proxy request timeout');
}

function request_uri($request) {
    if (preg_match('/^POST ([^ ]+) HTTP/m', $request, $matches)) {
        return $matches[1];
    }

    return null;
}

function header_value($request, $name) {
    foreach (preg_split('/\r?\n/', $request) as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) == 2 && strcasecmp($parts[0], $name) == 0) {
            return trim($parts[1]);
        }
    }

    return null;
}

$rr = new RequestReplayer();
$rr->flushInterval = 10000;
$rr->maxIteration = 100;
$rr->clearDumpedData();

$socketPath = str_replace("unix://", "", getenv("DD_TRACE_AGENT_URL"));
$proxyLog = '/tmp/unix-proxy-' . basename($socketPath);
@unlink($proxyLog);
$proxy = RequestReplayer::launchUnixProxy($socketPath);

show('recorded', \DDTrace\Internal\record_ffe_evaluation_metric(
    'unix.metric.flag',
    'blue',
    'SPLIT',
    null,
    'allocation-unix'
));
show('flushed', \DDTrace\Internal\flush_ffe_evaluation_metrics());

$request = wait_for_proxy_request($proxyLog);

show('metrics_uri', request_uri($request));
show('content_type', header_value($request, 'Content-Type'));
show('test_token', header_value($request, 'X-Datadog-Test-Session-Token'));
?>
--CLEAN--
<?php
@unlink("/tmp/ddtrace-ffe-metrics-test.socket");
@unlink("/tmp/unix-proxy-ddtrace-ffe-metrics-test.socket");
?>
--EXPECT--
recorded=true
flushed=true
metrics_uri="/v1/metrics"
content_type="application/x-protobuf"
test_token="ffe/evaluation_metrics_unix_agent_endpoint"
