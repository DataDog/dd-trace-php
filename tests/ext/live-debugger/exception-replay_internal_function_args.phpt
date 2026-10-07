--TEST--
Exception replay names the arguments of an internal function frame
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_EXCEPTION_REPLAY_ENABLED=1
DD_EXCEPTION_REPLAY_CAPTURE_INTERVAL_SECONDS=1
DD_DYNAMIC_INSTRUMENTATION_CAPTURE_TIMEOUT_MS=5000
DD_TRACE_AGENT_TEST_SESSION_TOKEN=live-debugger/exception-replay_internal_function_args
--INI--
zend.exception_ignore_args=0
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

function divide($a, $b) {
    return intdiv($a, $b);
}

try {
    divide(7, 0);
} catch (DivisionByZeroError $e) {
    $span = \DDTrace\start_span();
    $span->exception = $e;
    \DDTrace\close_span();
}

$dlr = new DebuggerLogReplayer;
$log = $dlr->waitForDebuggerDataAndReplay();
$log = json_decode($log["body"], true);

foreach ($log as $entry) {
    $snapshot = $entry["debugger"]["snapshot"];
    if (($snapshot["probe"]["location"]["method"] ?? null) === "intdiv") {
        $args = $snapshot["captures"]["return"]["arguments"];
        ksort($args);
        var_dump($args);
    }
}

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECT--
array(2) {
  ["num1"]=>
  array(2) {
    ["type"]=>
    string(3) "int"
    ["value"]=>
    string(1) "7"
  }
  ["num2"]=>
  array(2) {
    ["type"]=>
    string(3) "int"
    ["value"]=>
    string(1) "0"
  }
}
