--TEST--
Live debugger line probe on a file compiled after the probe arrived
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_DYNAMIC_INSTRUMENTATION_CAPTURE_TIMEOUT_MS=5000
DD_TRACE_AGENT_TEST_SESSION_TOKEN=live-debugger/line_probe_deferred
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

$inc = __DIR__ . "/debugger_line_probe_deferred.inc";
$names = [marker_line($inc, "deferred") => "deferred"];

await_probe_installation(function () use ($inc) {
    build_log_probe(["where" => ["sourceFile" => basename($inc), "lines" => [(string)marker_line($inc, "deferred")]], "captureSnapshot" => true, "segments" => [["str" => "deferred: "], ["json" => ["ref" => "arg"]]]]);

    \DDTrace\start_span(); // submit span data
});

// The file is not compiled yet, so nothing is armed.
$data = await_debugger_data(0, ["1" => ["RECEIVED"]]);
var_dump($data["statuses"]["1"]);

include $inc;
var_dump(deferred("now"));

$data = await_debugger_data(1, ["1" => ["INSTALLED", "EMITTING"]]);
echo json_encode(line_snapshot_summary($data["snapshots"]["1"][0], $names), JSON_UNESCAPED_SLASHES), "\n";

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECT--
array(1) {
  [0]=>
  string(8) "RECEIVED"
}
string(12) "deferred now"
{"message":"deferred: now","location":{"file":"debugger_line_probe_deferred.inc","lines":["deferred"]},"captures":{"line deferred":{"arguments":"arg"}},"top frame":"deferred:deferred"}
