--TEST--
Client-side stats: excess DD_TRACE_STATS_ADDITIONAL_TAGS keys and an invalid cardinality limit are reported once per configuration
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
            'X-Datadog-Test-Session-Token: client_side_stats_additional_tags_config_warnings',
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
DD_TRACE_STATS_ADDITIONAL_TAGS=e,d,c,b,a,a,f
DD_TRACE_STATS_ADDITIONAL_TAGS_CARDINALITY_LIMIT=0
DD_TRACE_LOG_LEVEL=warn,span=off,startup=off
--INI--
datadog.trace.log_file=file://stdout
datadog.env=test-env
datadog.version=1.2.3-additional-tags-warnings
datadog.trace.agent_test_session_token=client_side_stats_additional_tags_config_warnings
--FILE--
<?php

dd_trace_internal_fn('await_agent_info');

function stats_span($resource) {
    $root = \DDTrace\start_trace_span();
    $root->name = "web.request";
    $root->resource = $resource;
    $root->service = "stats-additional-tags-warnings";
    $root->meta['span.kind'] = 'client';
    \DDTrace\close_span();
}

// The configuration is read for every span, but each one is reported only once.
stats_span("first");
stats_span("second");
dd_trace_internal_fn('synchronous_flush');

echo "changing the configuration\n";
ini_set("datadog.trace.stats_additional_tags", "z,y,x,w,v");
ini_set("datadog.trace.stats_additional_tags_cardinality_limit", "-5");
stats_span("third");
stats_span("fourth");
dd_trace_internal_fn('synchronous_flush');

echo "done\n";

?>
--EXPECTF--
[ddtrace] [warning] [%d] DD_TRACE_STATS_ADDITIONAL_TAGS has 6 keys, more than the maximum of 4; dropping: e, f
[ddtrace] [warning] [%d] DD_TRACE_STATS_ADDITIONAL_TAGS_CARDINALITY_LIMIT must be positive and smaller than a million, got 0; using the default of 100
changing the configuration
[ddtrace] [warning] [%d] DD_TRACE_STATS_ADDITIONAL_TAGS has 5 keys, more than the maximum of 4; dropping: z
[ddtrace] [warning] [%d] DD_TRACE_STATS_ADDITIONAL_TAGS_CARDINALITY_LIMIT must be positive and smaller than a million, got -5; using the default of 100
done
