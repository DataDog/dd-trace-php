--TEST--
Client-side SHM span stats mask additional tag values of new keys past DD_TRACE_STATS_ADDITIONAL_TAGS_CARDINALITY_LIMIT
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
            'X-Datadog-Test-Session-Token: client_side_stats_additional_tags_cardinality',
        ],
        'content' => json_encode(['version' => '7.65.0', 'client_drop_p0s' => true]),
    ]
]);
file_get_contents('http://request-replayer/set-agent-info', false, $ctx);
?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=10000
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=1
DD_TRACE_STATS_COMPUTATION_ENABLED=1
DD_TRACE_STATS_ADDITIONAL_TAGS=tenant_id
DD_TRACE_STATS_ADDITIONAL_TAGS_CARDINALITY_LIMIT=2
DD_TRACE_LOG_LEVEL=off
--INI--
datadog.env=test-env
datadog.version=1.2.3-additional-tags-cardinality
datadog.trace.agent_test_session_token=client_side_stats_additional_tags_cardinality
--FILE--
<?php

include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer();

dd_trace_internal_fn('await_agent_info');

// The flush interval is long, so all spans land in one stats bucket, flushed by synchronous_flush.
$tenants = ['a', 'b', 'c', 'd', 'a', 'b'];
foreach ($tenants as $tenant) {
    $root = \DDTrace\start_trace_span();
    $root->name = "web.request";
    $root->resource = "GET /tenant";
    $root->service = "stats-additional-tags-cardinality";
    $root->meta['span.kind'] = 'client';
    $root->meta['tenant_id'] = $tenant;
    \DDTrace\close_span();
}

dd_trace_internal_fn('synchronous_flush');

$groups = [];
$hits = 0;
for ($i = 0; $i < 300 && $hits < count($tenants); $i++) {
    usleep(100000);
    foreach ($rr->replayAllStats() ?: [] as $request) {
        $payload = json_decode($request['body'], true);
        foreach ($payload['Stats'] ?? [] as $bucket) {
            foreach ($bucket['Stats'] ?? [] as $group) {
                if ($group['Service'] !== 'stats-additional-tags-cardinality') {
                    continue;
                }
                $tags = implode(',', $group['AdditionalMetricTags'] ?? []);
                $groups[$tags] = ($groups[$tags] ?? 0) + $group['Hits'];
                $hits += $group['Hits'];
            }
        }
    }
}

// Spans reach the concentrator over IPC until the worker has opened the shared memory, so which
// tenants come first is not fixed. Each tenant is either admitted as a whole, its later spans
// merging into its entry, or masked as a whole.
$blocked = $groups['tenant_id:tracer_blocked_value'] ?? 0;
unset($groups['tenant_id:tracer_blocked_value']);
$counts = array_count_values($tenants);
echo "admitted: ", count($groups), "\n";
foreach ($groups as $tags => $count) {
    $tenant = substr($tags, strlen('tenant_id:'));
    if ($count !== ($counts[$tenant] ?? -1)) {
        echo "ERROR: $tags has $count hits, expected ", $counts[$tenant] ?? 0, "\n";
    }
}
echo "total: ", $blocked + array_sum($groups), "\n";
echo "blocked: ", $blocked > 0 ? "yes" : "no", "\n";

?>
--EXPECT--
admitted: 2
total: 6
blocked: yes
