--TEST--
Background sender skips client-side stats, filtering and P0 dropping even with an active sidecar
--SKIPIF--
<?php if (PHP_OS === "WINNT") die("skip: legacy sender is unavailable on Windows"); ?>
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
            'X-Datadog-Test-Session-Token: client_side_stats_legacy_headers',
        ],
        'content' => json_encode(['version' => '7.65.0', 'client_drop_p0s' => true,
            'filter_tags' => ['require' => ['css-required:yes']]]),
    ]
]);
file_get_contents('http://request-replayer/set-agent-info', false, $ctx);
?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=1000000
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
DD_TRACE_SIDECAR_TRACE_SENDER=0
DD_REMOTE_CONFIG_ENABLED=1
DD_TRACE_AUTO_FLUSH_ENABLED=0
--INI--
datadog.env=test-env
datadog.version=1.2.3-basic
datadog.trace.agent_test_session_token=client_side_stats_legacy_headers
--FILE--
<?php

include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer();

// Load the stats-capable agent's configuration so CSS is skipped because of the sender choice.
dd_trace_internal_fn('await_agent_info');

$rr->clearDumpedData();
// CSS stays inactive with the background sender, including when explicitly enabled.
// The agent filter would reject these spans if local filtering were active.
foreach ([1, 0, 1, 0] as $i => $enabled) {
    ini_set('datadog.trace.stats_computation_enabled', (string)$enabled);
    $root = \DDTrace\start_trace_span();
    $root->name = "stats-$enabled-$i";
    $root->service = 'stats-test-service';
    \DDTrace\set_priority_sampling($i < 2 ? 0 : 1);
    \DDTrace\close_span();
    \DDTrace\flush();
}
dd_trace_internal_fn('synchronous_flush', 5000);
$headersBySpan = [];
foreach ($rr->replayAllRequests() as $request) {
    if (strpos($request['uri'], '/traces') === false) continue;
    $headers = array_change_key_case($request['headers'], CASE_LOWER);
    $computed = !empty($headers['datadog-client-computed-stats']) ? 'true' : 'false';
    $topLevel = !empty($headers['datadog-client-computed-top-level']) ? 'true' : 'false';
    foreach (json_decode($request['body'], true) as $trace) {
        foreach ($trace as $span) {
            if (strpos($span['name'], 'stats-') === 0) {
                $marked = isset($span['metrics']['_dd.top_level']) ? 'true' : 'false';
                $headersBySpan[$span['name']] = "stats=$computed top-level=$topLevel marked=$marked";
            }
        }
    }
}
ksort($headersBySpan);
foreach ($headersBySpan as $name => $headers) echo "$name: $headers\n";
echo "stats payloads: " . count($rr->replayAllStats() ?? []) . "\n";
?>
--EXPECT--
stats-0-1: stats=false top-level=false marked=false
stats-0-3: stats=false top-level=false marked=false
stats-1-0: stats=false top-level=false marked=false
stats-1-2: stats=false top-level=false marked=false
stats payloads: 0
