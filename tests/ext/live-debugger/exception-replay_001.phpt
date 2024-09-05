--TEST--
Test exception replay
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=1
DD_EXCEPTION_REPLAY_ENABLED=1
DD_EXCEPTION_REPLAY_RATE_LIMIT_SECONDS=1
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
$log = json_decode($log["body"], true);
foreach ($log[1]["debugger"]["snapshot"]["captures"] as &$capture) {
    ksort($capture["locals"]);
}
var_dump($log);

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECTF--
array(2) {
  [0]=>
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
      array(7) {
        ["language"]=>
        string(3) "php"
        ["id"]=>
        string(36) "%s"
        ["timestamp"]=>
        int(%d)
        ["exception_capture_id"]=>
        string(36) "%s"
        ["exception_hash"]=>
        string(%d) "%s"
        ["frame_index"]=>
        int(0)
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
  [1]=>
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
      array(7) {
        ["language"]=>
        string(3) "php"
        ["id"]=>
        string(36) "%s"
        ["timestamp"]=>
        int(%d)
        ["exception_capture_id"]=>
        string(36) "%s"
        ["exception_hash"]=>
        string(16) "0547bb1d4e434257"
        ["frame_index"]=>
        int(1)
        ["captures"]=>
        array(1) {
          ["return"]=>
          &array(1) {
            ["locals"]=>
            array(8) {
              ["_COOKIE"]=>
              array(1) {
                ["type"]=>
                string(5) "array"
              }
              ["_FILES"]=>
              array(1) {
                ["type"]=>
                string(5) "array"
              }
              ["_GET"]=>
              array(1) {
                ["type"]=>
                string(5) "array"
              }
              ["_POST"]=>
              array(1) {
                ["type"]=>
                string(5) "array"
              }
              ["_SERVER"]=>
              array(2) {
                ["type"]=>
                string(5) "array"
                ["entries"]=>
                array(%d) {
                %A
                }
              }
              ["argc"]=>
              array(2) {
                ["type"]=>
                string(3) "int"
                ["value"]=>
                string(1) "1"
              }
              ["argv"]=>
              array(2) {
                ["type"]=>
                string(5) "array"
                ["elements"]=>
                array(1) {
                  [0]=>
                  array(2) {
                    ["type"]=>
                    string(6) "string"
                    ["value"]=>
                    string(%d) "%s/exception-replay_001.php"
                  }
                }
              }
              ["globalvar"]=>
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
    ["message"]=>
    NULL
  }
}