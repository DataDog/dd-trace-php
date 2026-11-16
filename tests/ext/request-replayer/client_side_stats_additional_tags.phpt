--TEST--
Client-side SHM span stats include the span tags configured in DD_TRACE_STATS_ADDITIONAL_TAGS
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
            'X-Datadog-Test-Session-Token: client_side_stats_additional_tags',
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
DD_TRACE_STATS_ADDITIONAL_TAGS=tenant_id, region,region,zone,app,customer
DD_TRACE_LOG_LEVEL=off
--INI--
datadog.env=test-env
datadog.version=1.2.3-additional-tags
datadog.trace.agent_test_session_token=client_side_stats_additional_tags
--FILE--
<?php

include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer();

dd_trace_internal_fn('await_agent_info');

// Five distinct keys are configured: sorted, the first four are kept (app, customer, region,
// tenant_id) and zone is dropped.
$spans = [
    'both' => ['region' => 'us-east-1', 'tenant_id' => 'acme', 'zone' => 'z1'],
    'region-only' => ['region' => 'eu-west-1'],
    'none' => [],
    'oversized' => ['tenant_id' => str_repeat('x', 201)],
    'int' => ['tenant_id' => 42],
    'empty' => ['app' => ''],
    'customer' => ['customer' => 'big', 'app' => 'shop'],
];
foreach ($spans as $resource => $meta) {
    $root = \DDTrace\start_trace_span();
    $root->name = "web.request";
    $root->resource = $resource;
    $root->service = "stats-additional-tags";
    $root->meta['span.kind'] = 'client';
    foreach ($meta as $key => $value) {
        $root->meta[$key] = $value;
    }
    \DDTrace\close_span();
}

dd_trace_internal_fn('synchronous_flush');

// Stats may be split across payloads; collect until every span is accounted for.
$groups = [];
$hits = 0;
for ($i = 0; $i < 300 && $hits < count($spans); $i++) {
    usleep(100000);
    foreach ($rr->replayAllStats() ?: [] as $request) {
        $payload = json_decode($request['body'], true);
        foreach ($payload['Stats'] ?? [] as $bucket) {
            foreach ($bucket['Stats'] ?? [] as $group) {
                if ($group['Service'] !== 'stats-additional-tags') {
                    continue;
                }
                $key = $group['Resource'] . ': ' . json_encode($group['AdditionalMetricTags'] ?? []);
                $groups[$key] = ($groups[$key] ?? 0) + $group['Hits'];
                $hits += $group['Hits'];
            }
        }
    }
}
ksort($groups);
foreach ($groups as $key => $count) {
    echo "$key x$count\n";
}

?>
--EXPECT--
both: ["region:us-east-1","tenant_id:acme"] x1
customer: ["app:shop","customer:big"] x1
empty: ["app:"] x1
int: ["tenant_id:42"] x1
none: [] x1
oversized: ["tenant_id:tracer_blocked_value"] x1
region-only: ["region:eu-west-1"] x1
