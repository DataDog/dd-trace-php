--TEST--
Live debugger line probes evaluate expressions in the frame; @return, @duration and @exception do not exist at a line
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_DYNAMIC_INSTRUMENTATION_CAPTURE_TIMEOUT_MS=5000
DD_TRACE_AGENT_TEST_SESSION_TOKEN=live-debugger/line_probe_expressions
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

function target($arg) {
    $local = $arg + 1;
    return $local; // line:target
}

function catcher() {
    try {
        throw new Exception("caught");
    } catch (Exception $e) { // line:catch
        return $e->getMessage();
    }
}

$names = [marker_line(__FILE__, "target") => "target", marker_line(__FILE__, "catch") => "catch"];

await_probe_installation(function () {
    $file = basename(__FILE__);
    $target = (string)marker_line(__FILE__, "target");
    build_log_probe(["where" => ["sourceFile" => $file, "lines" => [$target]], "segments" => [
        ["str" => "return="], ["json" => ["ref" => "@return"]], ["str" => " duration="], ["json" => ["ref" => "@duration"]],
    ]]);
    build_log_probe(["where" => ["sourceFile" => $file, "lines" => [$target]], "when" => ["json" => ["gt" => [["ref" => "@duration"], 0]]], "segments" => [["str" => "never"]]]);
    // The caught exception is still pending while the catch line dispatches, but it is not @exception.
    build_log_probe(["where" => ["sourceFile" => $file, "lines" => [(string)marker_line(__FILE__, "catch")]], "segments" => [["str" => "exception="], ["json" => ["ref" => "@exception"]]]]);
    // Locals and arguments evaluate as anywhere else.
    build_log_probe(["where" => ["sourceFile" => $file, "lines" => [$target]], "when" => ["json" => ["gt" => [["ref" => "local"], ["ref" => "arg"]]]],
        "captureExpressions" => [["name" => "sum", "expr" => ["json" => ["ref" => "local"]]]],
        "segments" => [["str" => "local="], ["json" => ["ref" => "local"]]]]);

    \DDTrace\start_span(); // submit span data
}, 4);

var_dump(target(1), catcher());

$data = await_debugger_data(6);
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
int(2)
string(6) "caught"
probe 1: {"message":"Evaluation errors for probe id 1","location":{"file":"debugger_line_probe_expressions.php","lines":["target"]},"errors":["@return: Could not fetch variable @return","@duration: Could not fetch variable @duration"]}
probe 1: {"message":"return=UNDEFINED duration=UNDEFINED","location":{"file":"debugger_line_probe_expressions.php","lines":["target"]}}
probe 2: {"message":"Evaluation errors for probe id 2","location":{"file":"debugger_line_probe_expressions.php","lines":["target"]},"errors":["@duration > 0: Could not fetch variable @duration"]}
probe 3: {"message":"Evaluation errors for probe id 3","location":{"file":"debugger_line_probe_expressions.php","lines":["catch"]},"errors":["@exception: Could not fetch variable @exception"]}
probe 3: {"message":"exception=UNDEFINED","location":{"file":"debugger_line_probe_expressions.php","lines":["catch"]}}
probe 4: {"message":"local=2","location":{"file":"debugger_line_probe_expressions.php","lines":["target"]},"captures":{"line target":{"captureExpressions":"sum"}}}
