--TEST--
Live debugger span decoration probes on a line decorate the active span before or after it, and at both ends of a range
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_DYNAMIC_INSTRUMENTATION_CAPTURE_TIMEOUT_MS=5000
DD_TRACE_AGENT_TEST_SESSION_TOKEN=live-debugger/line_span_decoration_probe
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

function decorated($arg) {
    $local = $arg * 3; // line:assign
    return \DDTrace\active_span()->meta; // line:read
}

function tag($name) {
    return ["tags" => [["name" => $name, "value" => ["segments" => [["json" => ["ref" => "local"]]]]]]];
}

$span = await_probe_installation(function () {
    $file = basename(__FILE__);
    $assign = marker_line(__FILE__, "assign");
    $read = marker_line(__FILE__, "read");
    build_span_decoration_probe(["where" => ["sourceFile" => $file, "lines" => ["$read"]], "decorations" => [tag("entry")]]);
    build_span_decoration_probe(["where" => ["sourceFile" => $file, "lines" => ["$assign"]], "evaluateAt" => "EXIT", "decorations" => [tag("exit")]]);
    // Before its first line $local is undefined; after its last line it is set.
    build_span_decoration_probe(["where" => ["sourceFile" => $file, "lines" => ["$assign-$read"]], "decorations" => [tag("range")]]);

    return \DDTrace\start_span(); // submit span data
}, 3);

function dump_tags($meta) {
    $tags = array_intersect_key($meta, array_flip(["entry", "exit", "range", "_dd.di.range.evaluation_error"]));
    ksort($tags);
    var_dump($tags);
}

dump_tags(decorated(2));
dump_tags($span->meta);

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECTF--
array(4) {
  ["_dd.di.range.evaluation_error"]=>
  string(%d) "%s"
  ["entry"]=>
  string(1) "6"
  ["exit"]=>
  string(1) "6"
  ["range"]=>
  string(%d) "%s"
}
array(4) {
  ["_dd.di.range.evaluation_error"]=>
  string(%d) "%s"
  ["entry"]=>
  string(1) "6"
  ["exit"]=>
  string(1) "6"
  ["range"]=>
  string(1) "6"
}
