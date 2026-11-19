--TEST--
Client-side stats include inferred proxy roots in kept traces
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php
if (PHP_VERSION_ID < 70400) die("skip: Before PHP 7.4, the skip-task would cause the sidecar to fetch the info already.");
if (PHP_VERSION_ID >= 80100) {
    echo "nocache\n";
}
$ctx = stream_context_create([
    'http' => [
        'method' => 'PUT',
        'header' => [
            'Content-Type: application/json',
            'X-Datadog-Test-Session-Token: client_side_stats_inferred_root',
        ],
        'content' => json_encode(['version' => '7.65.0', 'client_drop_p0s' => true]),
    ]
]);
file_get_contents('http://request-replayer/set-agent-info', false, $ctx);
?>
--ENV--
DD_TRACE_INFERRED_PROXY_SERVICES_ENABLED=1
METHOD=GET
SERVER_NAME=localhost:8888
SCRIPT_NAME=/foo.php
REQUEST_URI=/foo
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_SERVICE=client_side_stats_inferred_root
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=1
--INI--
datadog.env=test-env
datadog.version=client_side_stats_inferred_root
datadog.trace.agent_test_session_token=client_side_stats_inferred_root
--FILE--
<?php

include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer();

// Block until the sidecar has received the agent's /info response before stats are computed
dd_trace_internal_fn('await_agent_info');
$rr->clearDumpedData();

$priority = 1;
$root = \DDTrace\start_trace_span();
$root->name = 'web.request';
$root->service = 'stats-test-service';
$headers = [
    'x-dd-proxy' => 'aws-apigateway',
    'x-dd-proxy-request-time-ms' => (string)(int)(microtime(true) * 1000 - 10),
    'x-dd-proxy-path' => '/test',
    'x-dd-proxy-httpmethod' => 'GET',
    'x-dd-proxy-domain-name' => 'example.com',
    'x-dd-proxy-stage' => 'aws-prod',
];
\DDTrace\consume_distributed_tracing_headers(function ($key) use ($headers) {
    return $headers[$key] ?? null;
});
\DDTrace\set_priority_sampling($priority);
\DDTrace\close_span();
\DDTrace\flush();
dd_trace_internal_fn('synchronous_flush', 5000);
$statsRequest = $rr->waitForStats();
$payload = json_decode($statsRequest['body'], true);
$hits = 0;
$traceRoots = [];
foreach ($payload['Stats'] as $bucket) {
    foreach ($bucket['Stats'] as $group) {
        if ($group['Name'] === 'aws.apigateway') $hits += $group['Hits'];
        if (in_array($group['Name'], ['aws.apigateway', 'web.request'])) {
            $traceRoots[$group['Name']] = $group['IsTraceRoot'];
        }
    }
}
echo "inferred root hits: $hits\n";
ksort($traceRoots);
foreach ($traceRoots as $name => $isTraceRoot) {
    echo "$name: IsTraceRoot=$isTraceRoot\n";
}
?>
--EXPECT--
inferred root hits: 1
aws.apigateway: IsTraceRoot=1
web.request: IsTraceRoot=2
