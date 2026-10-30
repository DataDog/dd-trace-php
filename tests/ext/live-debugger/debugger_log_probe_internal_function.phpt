--TEST--
Live debugger log probe on an internal function captures its passed arguments
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php if (PHP_VERSION_ID < 80000) die('skip: internal argument names differ before PHP 8'); ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_DYNAMIC_INSTRUMENTATION_CAPTURE_TIMEOUT_MS=5000
DD_TRACE_AGENT_TEST_SESSION_TOKEN=live-debugger/log_probe_internal_function
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

await_probe_installation(function() {
    build_log_probe([
        "where" => ["methodName" => "str_pad"],
        "captureSnapshot" => true,
        "segments" => [
            ["str" => "length="],
            ["json" => ["ref" => "length"]],
        ],
    ]);

    \DDTrace\start_span(); // submit span data
});

function caller() {
    $callerLocal = "not an argument of str_pad";
    return str_pad("ab", 4, "-"); // $pad_type is omitted
}

var_dump(caller());

$dlr = new DebuggerLogReplayer;
$log = $dlr->waitForDebuggerDataAndReplay();
$log = json_decode($log["body"], true)[0];
var_dump($log["message"]);
foreach ($log["debugger"]["snapshot"]["captures"] as $type => $capture) {
    ksort($capture["arguments"]);
    echo "$type:\n";
    var_dump($capture);
}

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECT--
string(4) "ab--"
string(8) "length=4"
entry:
array(1) {
  ["arguments"]=>
  array(3) {
    ["length"]=>
    array(2) {
      ["type"]=>
      string(3) "int"
      ["value"]=>
      string(1) "4"
    }
    ["pad_string"]=>
    array(2) {
      ["type"]=>
      string(6) "string"
      ["value"]=>
      string(1) "-"
    }
    ["string"]=>
    array(2) {
      ["type"]=>
      string(6) "string"
      ["value"]=>
      string(2) "ab"
    }
  }
}
return:
array(1) {
  ["arguments"]=>
  array(4) {
    ["@return"]=>
    array(2) {
      ["type"]=>
      string(6) "string"
      ["value"]=>
      string(4) "ab--"
    }
    ["length"]=>
    array(2) {
      ["type"]=>
      string(3) "int"
      ["value"]=>
      string(1) "4"
    }
    ["pad_string"]=>
    array(2) {
      ["type"]=>
      string(6) "string"
      ["value"]=>
      string(1) "-"
    }
    ["string"]=>
    array(2) {
      ["type"]=>
      string(6) "string"
      ["value"]=>
      string(2) "ab"
    }
  }
}
