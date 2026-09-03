--TEST--
Client-side span stats bucket by DD_TAGS env/version when DD_ENV/DD_VERSION are unset
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
            'X-Datadog-Test-Session-Token: client_side_stats_dd_tags',
        ],
        'content' => json_encode(['version' => '7.65.0', 'client_drop_p0s' => true]),
    ]
]);
file_get_contents('http://request-replayer/set-agent-info', false, $ctx);
?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=1
DD_TRACE_STATS_COMPUTATION_ENABLED=1
DD_TAGS=env:staging,version:9.9.9-tags
--INI--
datadog.trace.agent_test_session_token=client_side_stats_dd_tags
--FILE--
<?php

include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer();

// Block until the sidecar has received the agent's /info response before stats are computed
dd_trace_internal_fn('await_agent_info');

$root = \DDTrace\start_trace_span();
$root->name = "web.request";
$root->resource = "GET /test";
$root->service = "stats-test-service";
\DDTrace\close_span();

dd_trace_internal_fn('synchronous_flush', 5000);
$rr->waitForDataAndReplay();

$statsRequest = $rr->waitForStats();
$payload = json_decode($statsRequest['body'], true);

// env/version come from DD_TAGS (merged into meta) since DD_ENV/DD_VERSION are unset. Without the
// meta fallback in the stats path they would bucket by empty strings while traces carry the values.
echo "env: " . $payload['Env'] . "\n";
echo "version: " . $payload['Version'] . "\n";

?>
--EXPECT--
env: staging
version: 9.9.9-tags
