--TEST--
Live debugger log probes hit within one trace are sampled together
--DESCRIPTION--
A sampled probe opens a generation in its trace; every other probe that has not emitted in it yet
follows, regardless of its own budget. The 1/s probe on inner() leads the first generation, then
follows each generation the 100/s probe on outer() opened in the previous iteration: at least 4
snapshots of 5 calls, where independent sampling only yields one per second.
A condition which does not hold must not consume the budget of its probe.
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_DYNAMIC_INSTRUMENTATION_CAPTURE_TIMEOUT_MS=5000
DD_TRACE_AGENT_TEST_SESSION_TOKEN=live-debugger/coordinated_sampling
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

function coordinated_inner($x) { return $x + 1; }
function coordinated_outer($x) { return coordinated_inner($x) * 2; }
function coordinated_conditional($x) { return $x; }

await_probe_installation(function () {
    build_log_probe(["where" => ["methodName" => "coordinated_outer"], "sampling" => ["snapshotsPerSecond" => 100], "segments" => [["str" => "outer"]]]);
    build_log_probe(["where" => ["methodName" => "coordinated_inner"], "sampling" => ["snapshotsPerSecond" => 1], "segments" => [["str" => "inner"]]]);
    build_log_probe([
        "where" => ["methodName" => "coordinated_conditional"],
        "when" => ["json" => ["eq" => [["ref" => "x"], 3]]],
        "sampling" => ["snapshotsPerSecond" => 1],
        "segments" => [["str" => "conditional "], ["json" => ["ref" => "x"]]],
    ]);

    \DDTrace\start_span(); // submit span data
}, 3);

// A trace of its own, where nothing was sampled yet: every hit has to win its own budget.
\DDTrace\start_trace_span();
for ($x = 1; $x <= 5; ++$x) {
    coordinated_conditional($x);
}
$conditional_trace = \DDTrace\logs_correlation_trace_id();
\DDTrace\close_span();

$trace = \DDTrace\logs_correlation_trace_id();
for ($i = 0; $i < 5; ++$i) {
    coordinated_outer($i);
}

// Wait for the snapshots which must arrive, then collect any further ones for a while.
$data = await_debugger_data(10);
ob_start();
foreach (await_debugger_data(PHP_INT_MAX, [], 2)["snapshots"] as $id => $logs) {
    $data["snapshots"][$id] = array_merge(isset($data["snapshots"][$id]) ? $data["snapshots"][$id] : [], $logs);
}
ob_end_clean();
ksort($data["snapshots"]);
foreach ($data["snapshots"] as $id => $logs) {
    $traces = array_unique(array_map(function ($log) { return $log["dd.trace_id"]; }, $logs));
    $expected = $id == 3 ? $conditional_trace : $trace;
    $count = count($logs);
    if ($id == 2) {
        // It may lead the second call too if its budget refilled in between (slow runs).
        $count = $count >= 4 && $count <= 5 ? "4 or 5" : $count;
    }
    echo "probe $id: $count x ", $logs[0]["message"], ", in the expected trace: ", var_export($traces == [$expected], true), "\n";
}

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECT--
probe 1: 5 x outer, in the expected trace: true
probe 2: 4 or 5 x inner, in the expected trace: true
probe 3: 1 x conditional 3, in the expected trace: true
