--TEST--
Live debugger line probes match their file as a case-insensitive path suffix, in every matching file
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_DYNAMIC_INSTRUMENTATION_CAPTURE_TIMEOUT_MS=5000
DD_TRACE_AGENT_TEST_SESSION_TOKEN=live-debugger/line_probe_paths
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

include __DIR__ . "/line_probe_paths/a/Target.inc";
include __DIR__ . "/line_probe_paths/b/Target.inc";
$line = marker_line(__DIR__ . "/line_probe_paths/a/Target.inc", "target");

await_probe_installation(function () use ($line) {
    // A bare file name names every Target.inc, in any case. Two snapshots in a row need more than the default one per second.
    build_log_probe(["where" => ["sourceFile" => "TARGET.INC", "lines" => ["$line"]], "captureSnapshot" => true, "sampling" => ["snapshotsPerSecond" => 100], "segments" => [["str" => "any"]]]);
    // Backslashes become slashes, and case is ignored, so this names a/Target.inc alone.
    build_log_probe(["where" => ["sourceFile" => "Line_Probe_Paths\\A\\target.inc", "lines" => ["$line"]], "captureSnapshot" => true, "segments" => [["str" => "a only"]]]);
    // A leading directory the runtime path lacks matches nothing.
    build_log_probe(["where" => ["sourceFile" => "unknown-prefix/a/Target.inc", "lines" => ["$line"]], "segments" => [["str" => "never"]]]);

    \DDTrace\start_span(); // submit span data
}, 3);

var_dump(a_target(), b_target());

$data = await_debugger_data(3, ["3" => ["RECEIVED"]]);
$summaries = [];
foreach ($data["snapshots"] as $id => $logs) {
    foreach ($logs as $log) {
        $summaries[] = "probe $id: " . json_encode(line_snapshot_summary($log, [$line => "target"]), JSON_UNESCAPED_SLASHES);
    }
}
sort($summaries);
echo implode("\n", $summaries), "\n";
var_dump($data["statuses"]["3"]);

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECT--
string(1) "a"
string(1) "b"
probe 1: {"message":"any","location":{"file":"TARGET.INC","lines":["target"]},"captures":{"line target":[]},"top frame":"a_target:target"}
probe 1: {"message":"any","location":{"file":"TARGET.INC","lines":["target"]},"captures":{"line target":[]},"top frame":"b_target:target"}
probe 2: {"message":"a only","location":{"file":"Line_Probe_Paths/A/target.inc","lines":["target"]},"captures":{"line target":[]},"top frame":"a_target:target"}
array(1) {
  [0]=>
  string(8) "RECEIVED"
}
