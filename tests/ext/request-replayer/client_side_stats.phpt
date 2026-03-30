--TEST--
Client-side SHM span stats are computed and flushed to the agent on trace flush
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=1
DD_TRACE_STATS_COMPUTATION_ENABLED=1
DD_ENV=test-env
DD_VERSION=1.2.3
--INI--
datadog.trace.agent_test_session_token=client_side_stats
--FILE--
<?php

include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer();

$root = \DDTrace\start_trace_span();
$root->name = "web.request";
$root->resource = "GET /test";
$root->service = "stats-test-service";
\DDTrace\close_span();

\DDTrace\flush();
$rr->waitForDataAndReplay();

// The request-replayer stores the msgpack-decoded body as JSON, with OkSummary/ErrorSummary
// (binary DDSketch fields) hex-encoded. We json_decode it to get the payload.
$statsRequest = $rr->waitForStats();
$payload = json_decode($statsRequest['body'], true);

echo "env: " . $payload['Env'] . "\n";
echo "version: " . $payload['Version'] . "\n";

$buckets = $payload['Stats'];
$found = false;
foreach ($buckets as $bucket) {
    foreach ($bucket['Stats'] as $group) {
        if ($group['Service'] === 'stats-test-service' && $group['Name'] === 'web.request') {
            echo "service: " . $group['Service'] . "\n";
            echo "name: " . $group['Name'] . "\n";
            echo "resource: " . $group['Resource'] . "\n";
            echo "hits >= 1: " . ($group['Hits'] >= 1 ? "true" : "false") . "\n";
            echo "errors: " . $group['Errors'] . "\n";
            $found = true;
            break 2;
        }
    }
}
if (!$found) {
    echo "ERROR: no matching stats group found\n";
    var_dump($payload);
}

?>
--EXPECT--
env: test-env
version: 1.2.3
service: stats-test-service
name: web.request
resource: GET /test
hits >= 1: true
errors: 0
