--TEST--
Live debugger line probes instrument every where.lines entry, and reject partially overlapping ranges
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_DYNAMIC_INSTRUMENTATION_CAPTURE_TIMEOUT_MS=5000
DD_TRACE_AGENT_TEST_SESSION_TOKEN=live-debugger/line_probe_multiple_lines
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

function first() {
    return "first"; // line:first
}

function second() {
    $x = 1; // line:a
    $y = 2; // line:b
    $z = 3; // line:c
    return $x + $y + $z;
}

$file = basename(__FILE__);
$names = [];
foreach (["first", "a", "b", "c"] as $marker) {
    $names[$marker] = marker_line(__FILE__, $marker);
}
extract($names);

await_probe_installation(function () use ($file, $first, $a, $b, $c) {
    // Two single lines in different functions. Capturing probes default to one snapshot per second.
    build_log_probe(["where" => ["sourceFile" => $file, "lines" => ["$first", "$a"]], "captureSnapshot" => true, "sampling" => ["snapshotsPerSecond" => 100], "segments" => [["str" => "hit"]]]);
    // A line nested inside a range of the same probe.
    build_log_probe(["where" => ["sourceFile" => $file, "lines" => ["$a-$c", "$b"]], "captureSnapshot" => true, "sampling" => ["snapshotsPerSecond" => 100], "segments" => [["str" => "nested"]]]);

    \DDTrace\start_span(); // submit span data
}, 4);

// Ranges sharing a boundary line partially overlap, within one probe and against an installed one.
build_log_probe(["where" => ["sourceFile" => $file, "lines" => ["$a-$b", "$b-$c"]], "segments" => [["str" => "never"]]]);
build_log_probe(["where" => ["sourceFile" => $file, "lines" => ["$first-$a"]], "segments" => [["str" => "never"]]]);

$data = await_debugger_data(0, ["3" => ["ERROR"], "4" => ["ERROR"]]);
var_dump($data["statuses"]["3"], $data["statuses"]["4"]);

var_dump(first(), second());

$data = await_debugger_data(4);
$summaries = [];
foreach ($data["snapshots"] as $id => $logs) {
    foreach ($logs as $log) {
        $summaries[] = "probe $id: " . json_encode(line_snapshot_summary($log, array_flip($names)), JSON_UNESCAPED_SLASHES);
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
array(1) {
  [0]=>
  string(50) "ERROR: Line ranges of this probe partially overlap"
}
array(1) {
  [0]=>
  string(76) "ERROR: Line hook range partially overlaps an existing range in the same file"
}
string(5) "first"
int(6)
probe 1: {"message":"hit","location":{"file":"debugger_line_probe_multiple_lines.php","lines":["first","a"]},"captures":{"line a":[]},"top frame":"second:a"}
probe 1: {"message":"hit","location":{"file":"debugger_line_probe_multiple_lines.php","lines":["first","a"]},"captures":{"line first":[]},"top frame":"first:first"}
probe 2: {"message":"nested","location":{"file":"debugger_line_probe_multiple_lines.php","lines":["a-c","b"]},"captures":{"line a":[],"line c":{"locals":"x, y, z"}},"top frame":"second:a"}
probe 2: {"message":"nested","location":{"file":"debugger_line_probe_multiple_lines.php","lines":["a-c","b"]},"captures":{"line b":{"locals":"x"}},"top frame":"second:b"}
