--TEST--
Live debugger coordinated sampling is scoped to a trace
--DESCRIPTION--
Generations opened in one trace (root span) are not followed in another: with a trace per call,
the 1/s probe on inner() emits only for the first call, the 100/s probe on outer() for every call.
Without any trace, each probe samples on its own and snapshots carry no trace correlation.
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_DYNAMIC_INSTRUMENTATION_CAPTURE_TIMEOUT_MS=5000
DD_TRACE_AGENT_TEST_SESSION_TOKEN=live-debugger/coordinated_sampling_traces
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

function per_trace_inner($x) { return $x + 1; }
function per_trace_outer($x) { return per_trace_inner($x) * 2; }
function untraced_inner($x) { return $x + 1; }
function untraced_outer($x) { return untraced_inner($x) * 2; }

await_probe_installation(function () {
    build_log_probe(["where" => ["methodName" => "per_trace_outer"], "sampling" => ["snapshotsPerSecond" => 100], "segments" => [["str" => "per trace outer"]]]);
    build_log_probe(["where" => ["methodName" => "per_trace_inner"], "sampling" => ["snapshotsPerSecond" => 1], "segments" => [["str" => "per trace inner"]]]);
    build_log_probe(["where" => ["methodName" => "untraced_outer"], "sampling" => ["snapshotsPerSecond" => 100], "segments" => [["str" => "untraced outer"]]]);
    build_log_probe(["where" => ["methodName" => "untraced_inner"], "sampling" => ["snapshotsPerSecond" => 1], "segments" => [["str" => "untraced inner"]]]);

    \DDTrace\start_span(); // submit span data
}, 4);

$traces = [];
for ($i = 0; $i < 5; ++$i) {
    \DDTrace\start_trace_span();
    per_trace_outer($i);
    $traces[] = \DDTrace\logs_correlation_trace_id();
    \DDTrace\close_span();
}

\DDTrace\close_span(); // the root span: no trace from here on
for ($i = 0; $i < 5; ++$i) {
    untraced_outer($i);
}

// One more than expected, to show that nothing else is emitted.
$data = await_debugger_data(13, [], 3);
foreach ($data["snapshots"] as $id => $logs) {
    $correlated = array_map(function ($log) { return isset($log["dd.trace_id"]) ? $log["dd.trace_id"] : null; }, $logs);
    echo "probe $id: ", count($logs), " x ", $logs[0]["message"], ", traces: ";
    if ($id <= 2) {
        // One snapshot per trace from the first, in whatever order they arrived.
        sort($correlated);
        $expected = array_slice($traces, 0, count($logs));
        sort($expected);
        echo var_export($correlated == $expected, true), "\n";
    } else {
        echo json_encode(array_unique($correlated)), "\n";
    }
}

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECT--
Missing 1 snapshot(s)
probe 1: 5 x per trace outer, traces: true
probe 2: 1 x per trace inner, traces: true
probe 3: 5 x untraced outer, traces: [null]
probe 4: 1 x untraced inner, traces: [null]
