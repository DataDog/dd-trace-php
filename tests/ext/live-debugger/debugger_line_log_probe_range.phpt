--TEST--
Live debugger log probe on a line range captures both ends in one snapshot
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_DYNAMIC_INSTRUMENTATION_CAPTURE_TIMEOUT_MS=5000
DD_TRACE_AGENT_TEST_SESSION_TOKEN=live-debugger/line_log_probe_range
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

function compute($n) {
    $a = $n * 2; // line:from
    $b = $a + 1;
    if ($n < 0) {
        throw new Exception("negative");
    }
    $c = $b * 3; // line:till
    return $c;
}

$names = [marker_line(__FILE__, "from") => "from", marker_line(__FILE__, "till") => "till"];

await_probe_installation(function () {
    build_log_probe([
        "where" => ["sourceFile" => basename(__FILE__), "lines" => [marker_line(__FILE__, "from") . "-" . marker_line(__FILE__, "till")]],
        "captureSnapshot" => true,
        // Capturing probes default to one snapshot per second.
        "sampling" => ["snapshotsPerSecond" => 100],
        // Checked once, before the first line of the range.
        "when" => ["json" => ["ne" => [["ref" => "n"], 0]]],
        // Evaluated when the range ends.
        "segments" => [["str" => "b="], ["json" => ["ref" => "b"]]],
    ]);

    \DDTrace\start_span(); // submit span data
});

var_dump(compute(1));
var_dump(compute(0));
try {
    compute(-1);
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}

$data = await_debugger_data(2);
$summaries = [];
foreach ($data["snapshots"] as $id => $logs) {
    foreach ($logs as $log) {
        $summaries[] = "probe $id: " . json_encode(line_snapshot_summary($log, $names), JSON_UNESCAPED_SLASHES);
    }
}
sort($summaries);
echo implode("\n", $summaries), "\n";

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECT--
int(9)
int(3)
negative
probe 1: {"message":"b=-1","location":{"file":"debugger_line_log_probe_range.php","lines":["from-till"]},"captures":{"line from":{"arguments":"n"},"line till":{"arguments":"n","locals":"a, b"}},"top frame":"compute:from"}
probe 1: {"message":"b=3","location":{"file":"debugger_line_log_probe_range.php","lines":["from-till"]},"captures":{"line from":{"arguments":"n"},"line till":{"arguments":"n","locals":"a, b, c"}},"top frame":"compute:from"}
