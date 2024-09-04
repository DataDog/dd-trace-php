--TEST--
Installing an
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=1
DD_EXCEPTION_DEBUGGING_ENABLED=1
--INI--
datadog.trace.agent_test_session_token=live-debugger/exception-replay_001
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

try {
    function foo($foo) {
        $localVar = [1];
        throw new Exception("test");
    }

    $globalvar = 1;
    foo((object)["val" => STDIN]);
} catch (Exception $e) {
    $span = \DDTrace\start_span();
    $span->exception = $e;
    \DDTrace\close_span();
}

$dlr = new DebuggerLogReplayer;
$log = $dlr->waitForDebuggerDataAndReplay();
$log = json_decode($log["body"], true)[0];
var_dump($log);

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECTF--
array(5) {
  ["service"]=>
  string(24) "exception-replay_001.php"
  ["source"]=>
  string(11) "dd_debugger"
  ["timestamp"]=>
  int(%d)
  ["debugger"]=>
  array(1) {
    ["snapshot"]=>
    array(5) {
      ["language"]=>
      string(3) "php"
      ["id"]=>
      string(36) "%s"
      ["timestamp"]=>
      int(%d)
      ["exception-id"]=>
      string(36) "%s"
      ["captures"]=>
      array(1) {
        ["return"]=>
        array(2) {
          ["arguments"]=>
          array(1) {
            ["foo"]=>
            array(2) {
              ["type"]=>
              string(8) "stdClass"
              ["fields"]=>
              array(1) {
                ["val"]=>
                array(2) {
                  ["type"]=>
                  string(6) "stream"
                  ["value"]=>
                  string(1) "1"
                }
              }
            }
          }
          ["locals"]=>
          array(1) {
            ["localVar"]=>
            array(2) {
              ["type"]=>
              string(5) "array"
              ["elements"]=>
              array(1) {
                [0]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(1) "1"
                }
              }
            }
          }
        }
      }
    }
  }
  ["message"]=>
  NULL
}
