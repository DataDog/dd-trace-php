--TEST--
Client-side stats: a child whose service maps (DD_SERVICE_MAPPING) to its parent's is not top-level
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
            'X-Datadog-Test-Session-Token: client_side_stats_top_level_service_mapping',
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
DD_TRACE_LOG_LEVEL=off
DD_SERVICE_MAPPING=svc-a:mapped,svc-b:mapped
--INI--
datadog.env=test-env
datadog.version=1.2.3-mapping
datadog.trace.agent_test_session_token=client_side_stats_top_level_service_mapping
--FILE--
<?php

include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer();

dd_trace_internal_fn('await_agent_info');

$root = \DDTrace\start_trace_span();
$root->name = "root.op";
$root->resource = "root.op";
$root->service = "svc-a";

foreach (["child.mapped_alike" => "svc-b", "child.unmapped" => "other"] as $name => $service) {
    $child = \DDTrace\start_span();
    $child->name = $name;
    $child->resource = $name;
    $child->service = $service;
    $child->metrics['_dd.measured'] = 1; // stats-eligible, top-level or not
    \DDTrace\close_span();
}

\DDTrace\close_span();

dd_trace_internal_fn('synchronous_flush');
$rr->waitForDataAndReplay();

$groups = [];
$rr->waitForStats(function ($request) use (&$groups) {
    foreach (json_decode($request['body'], true)['Stats'] ?? [] as $bucket) {
        foreach ($bucket['Stats'] ?? [] as $group) {
            $groups[$group['Name']] = $group;
        }
    }
    return isset($groups['root.op'], $groups['child.mapped_alike'], $groups['child.unmapped']);
});

foreach (['child.mapped_alike', 'child.unmapped', 'root.op'] as $name) {
    echo $name, " (", $groups[$name]['Service'], "): TopLevelHits=", $groups[$name]['TopLevelHits'] ?? 0, "\n";
}

?>
--EXPECT--
child.mapped_alike (mapped): TopLevelHits=0
child.unmapped (other): TopLevelHits=1
root.op (mapped): TopLevelHits=1
