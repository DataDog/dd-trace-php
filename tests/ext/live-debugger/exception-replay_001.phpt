--TEST--
Test exception replay
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support %A in EXPECTF"); ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
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
    ["ddsource"]=>
    string(11) "dd_debugger"
    ["timestamp"]=>
    int(%d)
    ["debugger"]=>
    array(1) {
      ["snapshot"]=>
      array(8) {
        ["language"]=>
        string(3) "php"
        ["id"]=>
        string(36) "%s"
        ["timestamp"]=>
        int(%d)
        ["exceptionCaptureId"]=>
        string(36) "%s"
        ["exceptionHash"]=>
        string(%d) "%s"
        ["frameIndex"]=>
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
        ["probe"]=>
        array(2) {
          ["id"]=>
          string(0) ""
          ["location"]=>
          array(1) {
            ["method"]=>
            string(3) "foo"
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
    ["ddsource"]=>
    string(11) "dd_debugger"
    ["timestamp"]=>
    int(%d)
    ["debugger"]=>
    array(1) {
      ["snapshot"]=>
      array(8) {
        ["language"]=>
        string(3) "php"
        ["id"]=>
        string(36) "%s"
        ["timestamp"]=>
        int(%d)
        ["exceptionCaptureId"]=>
        string(36) "%s"
        ["exceptionHash"]=>
        string(16) "0547bb1d4e434257"
        ["frameIndex"]=>
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
              array(%d) {
                ["type"]=>
                string(5) "array"
                ["entries"]=>
                array(%d) {
                %A
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
                    string(%d) "%sexception-replay_001.php"
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
        ["probe"]=>
        array(2) {
          ["id"]=>
          string(0) ""
          ["location"]=>
          array(0) {
          }
        }
      }
    }
    ["message"]=>
    NULL
  }
}