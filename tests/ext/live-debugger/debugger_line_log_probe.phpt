--TEST--
Live debugger log probes on a single line, before (ENTRY) and after (EXIT) it
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_DYNAMIC_INSTRUMENTATION_CAPTURE_TIMEOUT_MS=5000
DD_TRACE_AGENT_TEST_SESSION_TOKEN=live-debugger/line_log_probe
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

class Bar {
    var $prop = 1;

    function foo($arg) {
        $before = "before";
        // line:comment
        $assigned = "on the line"; // line:probe
        return $arg . $before . $assigned; // line:after
    }
}

$names = [];
foreach (["comment", "probe", "after"] as $marker) {
    $names[marker_line(__FILE__, $marker)] = $marker;
}

await_probe_installation(function () {
    $file = basename(__FILE__);
    $line = marker_line(__FILE__, "probe");
    // No evaluateAt: a line probe evaluates before its line.
    build_log_probe(["where" => ["sourceFile" => $file, "lines" => [(string)$line]], "captureSnapshot" => true, "segments" => [["str" => "before: "], ["json" => ["ref" => "before"]]]]);
    // A bare integer line, evaluated after the line ran.
    build_log_probe(["where" => ["sourceFile" => $file, "lines" => [$line]], "evaluateAt" => "EXIT", "captureSnapshot" => true, "segments" => [["str" => "after: "], ["json" => ["ref" => "assigned"]]]]);
    // A comment line binds to the next executable line, but keeps its own key.
    build_log_probe(["where" => ["sourceFile" => $file, "lines" => [(string)marker_line(__FILE__, "comment")]], "captureSnapshot" => true, "segments" => [["str" => "slid"]]]);

    \DDTrace\start_span(); // submit span data
}, 3);

var_dump((new Bar)->foo("x"));

$data = await_debugger_data(3);
foreach ($data["snapshots"] as $id => $logs) {
    echo "probe $id: ", json_encode(line_snapshot_summary($logs[0], $names), JSON_UNESCAPED_SLASHES), "\n";
}

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECT--
string(18) "xbeforeon the line"
probe 1: {"message":"before: before","location":{"file":"debugger_line_log_probe.php","lines":["probe"]},"captures":{"line probe":{"arguments":"arg, this","locals":"before"}},"top frame":"Bar::foo:probe"}
probe 2: {"message":"after: on the line","location":{"file":"debugger_line_log_probe.php","lines":["probe"]},"captures":{"line probe":{"arguments":"arg, this","locals":"assigned, before"}},"top frame":"Bar::foo:after"}
probe 3: {"message":"slid","location":{"file":"debugger_line_log_probe.php","lines":["comment"]},"captures":{"line comment":{"arguments":"arg, this","locals":"before"}},"top frame":"Bar::foo:probe"}
