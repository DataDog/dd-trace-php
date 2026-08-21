--TEST--
Live debugger log probe capture timeout with large data structure
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_DYNAMIC_INSTRUMENTATION_CAPTURE_TIMEOUT_MS=1
--INI--
datadog.trace.agent_test_session_token=live-debugger/log_probe_capture_timeout
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

function large_capture($huge_array) {}

await_probe_installation(function() {
    build_log_probe([
        "where" => ["methodName" => "large_capture"],
        "captureSnapshot" => true,
        "segments" => [["str" => "capture timeout test"]],
    ]);
    \DDTrace\start_span();
});

// 3-level array: 10 outer x 100 mid x 100 inner strings (10-char each)
// ~100,000 capture operations: reliably exceeds the 1ms CPU-time timeout on POSIX platforms.
// Windows' timer queue only has ~15ms wall-clock granularity, so on Windows the 1MB size cap
// fires first instead - both share the same abort mechanism, so either reason is acceptable here.
$data = [];
for ($i = 0; $i < 99; $i++) {
    $data[] = array_fill(0, 10, array_fill(0, 100, str_repeat('x', 10)));
}
$last = array_fill(0, 99, str_repeat('x', 10));
$last[] = 'LAST_SENTINEL';
$data[] = $last;

large_capture($data);

$dlr = new DebuggerLogReplayer;
$log = $dlr->waitForDebuggerDataAndReplay();
$captures = json_decode($log["body"], true)[0]["debugger"]["snapshot"]["captures"];
$captures_json = json_encode($captures);

// Snapshot was delivered with some captured data
var_dump(!empty($captures));

// An abort reason (CPU timeout, or the size limit on platforms with coarser timers) must appear
var_dump(strpos($captures_json, '"timeout"') !== false || strpos($captures_json, '"size"') !== false);

// The last element must NOT have been captured before the timeout fired
var_dump(strpos($captures_json, 'LAST_SENTINEL') === false);

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
