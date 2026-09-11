--TEST--
A failed capture-expression evaluation is reported, not silently dropped
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
--INI--
datadog.trace.agent_test_session_token=live-debugger/capture_expression_error
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

class Bar {
    function foo($arg1) {
        return $arg1;
    }
}

await_probe_installation(function() {
    build_log_probe([
        "where" => ["typeName" => "Bar", "methodName" => "foo"],
        "segments" => [["str" => "log"]],
        "captureExpressions" => [
            // getmember on an int cannot evaluate -> per-expression error
            ["name" => "bad", "expr" => ["json" => ["getmember" => [["ref" => "arg1"], "nope"]]]],
        ],
    ]);

    \DDTrace\start_span(); // submit span data
});

(new Bar)->foo(10);

$dlr = new DebuggerLogReplayer;
$errors = null;
for ($i = 0; $i < 3 && $errors === null; $i++) {
    $log = json_decode($dlr->waitForDebuggerDataAndReplay()["body"], true)[0];
    if (isset($log["debugger"]["snapshot"]["evaluationErrors"])) {
        $errors = $log["debugger"]["snapshot"]["evaluationErrors"];
    }
}
var_dump($errors);

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECTF--
array(1) {
  [0]=>
  array(2) {
    ["expr"]=>
    string(%d) "%s"
    ["message"]=>
    string(%d) "%s"
  }
}
