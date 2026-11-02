--TEST--
Client-side stats: DD_TRACE_STATS_ADDITIONAL_TAGS can be changed at runtime
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
            'X-Datadog-Test-Session-Token: client_side_stats_additional_tags_runtime',
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
DD_TRACE_STATS_ADDITIONAL_TAGS=tenant_id
DD_TRACE_LOG_LEVEL=off
--INI--
datadog.env=test-env
datadog.version=1.2.3-additional-tags-runtime
datadog.trace.agent_test_session_token=client_side_stats_additional_tags_runtime
--FILE--
<?php

include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer();

dd_trace_internal_fn('await_agent_info');

function stats_span($resource) {
    $root = \DDTrace\start_trace_span();
    $root->name = "web.request";
    $root->resource = $resource;
    $root->service = "stats-additional-tags-runtime";
    $root->meta['span.kind'] = 'client';
    $root->meta['tenant_id'] = 'acme';
    $root->meta['region'] = 'eu';
    \DDTrace\close_span();
}

stats_span("before");
dd_trace_internal_fn('synchronous_flush');
ini_set("datadog.trace.stats_additional_tags", "region");
stats_span("after");
dd_trace_internal_fn('synchronous_flush');

$groups = [];
$hits = 0;
for ($i = 0; $i < 300 && $hits < 2; $i++) {
    usleep(100000);
    foreach ($rr->replayAllStats() ?: [] as $request) {
        $payload = json_decode($request['body'], true);
        foreach ($payload['Stats'] ?? [] as $bucket) {
            foreach ($bucket['Stats'] ?? [] as $group) {
                if ($group['Service'] !== 'stats-additional-tags-runtime') {
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
after: ["region:eu"] x1
before: ["tenant_id:acme"] x1
