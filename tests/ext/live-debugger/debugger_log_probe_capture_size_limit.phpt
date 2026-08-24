--TEST--
Live debugger log probe capture is bounded in size
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_DYNAMIC_INSTRUMENTATION_CAPTURE_TIMEOUT_MS=0
--INI--
datadog.trace.agent_test_session_token=live-debugger/log_probe_capture_size_limit
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

function large_capture($huge_array) {}

await_probe_installation(function() {
    build_log_probe([
        "where" => ["methodName" => "large_capture"],
        "captureSnapshot" => true,
        "segments" => [["str" => "capture size limit test"]],
    ]);
    \DDTrace\start_span();
});

// The capture timeout is disabled above, so only the size limit can stop this capture:
// 100 x 100 strings of 255 chars (i.e. exactly maxLength) amount to ~2.5 MB of capture data.
$data = [];
for ($i = 0; $i < 99; $i++) {
    $data[] = array_fill(0, 100, str_repeat('x', 255));
}
$last = array_fill(0, 99, str_repeat('x', 255));
$last[] = 'LAST_SENTINEL';
$data[] = $last;

large_capture($data);

$dlr = new DebuggerLogReplayer;
$log = $dlr->waitForDebuggerDataAndReplay();
$captures = json_decode($log["body"], true)[0]["debugger"]["snapshot"]["captures"];
$captures_json = json_encode($captures);

// Snapshot was delivered with some captured data
var_dump(!empty($captures));

// The capture was cut short due to its size
var_dump(strpos($captures_json, '"notCapturedReason":"size"') !== false);

// The last element must NOT have been captured, the limit is hit way before
var_dump(strpos($captures_json, 'LAST_SENTINEL') === false);

// Roughly 1 MB, plus the slack of the last captured value
var_dump(strlen($captures_json) < 1.5 * 1024 * 1024);

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
bool(true)
