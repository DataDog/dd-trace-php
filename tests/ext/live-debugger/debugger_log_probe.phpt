--TEST--
Installing a live debugger log probe
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

class Bar {
    var $prop = [1, 2];

    function foo($arg1, $arg2) {
        $value = [true];
        return $arg1 + $arg2;
    }
}


await_probe_installation(function() {
    build_log_probe([
        "where" => ["typeName" => "Bar", "methodName" => "foo"],
        "captureSnapshot" => true,
        "segments" => [
            ["json" => ["filter" => [["ref" => "value"], ["not" => ["isDefined" => ["getmember" => [["ref" => "@it"], "foo"]]]]]]],
            ["str" => "\n"],
            ["json" => ["filter" => [["ref" => "value"], ["instanceof" => [["ref" => "@it"], "bool"]]]]],
            ["str" => "\n"],
            ["json" => ["filter" => [["ref" => "value"], ["instanceof" => [["ref" => "this"], "Bar"]]]]],
            ["str" => "\n"],
        ],
    ]);

    \DDTrace\start_span(); // submit span data
});

var_dump((new Bar)->foo(10, "20", [true]));

$dlr = new DebuggerLogReplayer;
$log = $dlr->waitForDebuggerDataAndReplay();
$log = json_decode($log["body"], true)[0];
foreach ($log["debugger"]["snapshot"]["captures"] as &$capture) {
    ksort($capture["arguments"]);
}
var_dump($log);

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECTF--
int(30)
array(5) {
  ["service"]=>
  string(22) "debugger_log_probe.php"
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
      string(%d) "%s"
      ["timestamp"]=>
      int(%d)
      ["captures"]=>
      array(2) {
        ["entry"]=>
        array(1) {
          ["arguments"]=>
          array(3) {
            ["arg1"]=>
            array(2) {
              ["type"]=>
              string(3) "int"
              ["value"]=>
              string(2) "10"
            }
            ["arg2"]=>
            array(2) {
              ["type"]=>
              string(6) "string"
              ["value"]=>
              string(2) "20"
            }
            ["this"]=>
            array(2) {
              ["type"]=>
              string(3) "Bar"
              ["fields"]=>
              array(1) {
                ["prop"]=>
                array(2) {
                  ["type"]=>
                  string(5) "array"
                  ["elements"]=>
                  array(2) {
                    [0]=>
                    array(2) {
                      ["type"]=>
                      string(3) "int"
                      ["value"]=>
                      string(1) "1"
                    }
                    [1]=>
                    array(2) {
                      ["type"]=>
                      string(3) "int"
                      ["value"]=>
                      string(1) "2"
                    }
                  }
                }
              }
            }
          }
        }
        ["return"]=>
        &array(2) {
          ["arguments"]=>
          array(4) {
            ["@return"]=>
            array(2) {
              ["type"]=>
              string(3) "Bar"
              ["fields"]=>
              array(1) {
                ["prop"]=>
                array(2) {
                  ["type"]=>
                  string(5) "array"
                  ["elements"]=>
                  array(2) {
                    [0]=>
                    array(2) {
                      ["type"]=>
                      string(3) "int"
                      ["value"]=>
                      string(1) "1"
                    }
                    [1]=>
                    array(2) {
                      ["type"]=>
                      string(3) "int"
                      ["value"]=>
                      string(1) "2"
                    }
                  }
                }
              }
            }
            ["arg1"]=>
            array(2) {
              ["type"]=>
              string(3) "int"
              ["value"]=>
              string(2) "10"
            }
            ["arg2"]=>
            array(2) {
              ["type"]=>
              string(6) "string"
              ["value"]=>
              string(2) "20"
            }
            ["this"]=>
            array(2) {
              ["type"]=>
              string(3) "Bar"
              ["fields"]=>
              array(1) {
                ["prop"]=>
                array(2) {
                  ["type"]=>
                  string(5) "array"
                  ["elements"]=>
                  array(2) {
                    [0]=>
                    array(2) {
                      ["type"]=>
                      string(3) "int"
                      ["value"]=>
                      string(1) "1"
                    }
                    [1]=>
                    array(2) {
                      ["type"]=>
                      string(3) "int"
                      ["value"]=>
                      string(1) "2"
                    }
                  }
                }
              }
            }
          }
          ["locals"]=>
          array(1) {
            ["value"]=>
            array(2) {
              ["type"]=>
              string(5) "array"
              ["elements"]=>
              array(1) {
                [0]=>
                array(2) {
                  ["type"]=>
                  string(4) "bool"
                  ["value"]=>
                  string(4) "true"
                }
              }
            }
          }
        }
      }
      ["probe"]=>
      array(2) {
        ["id"]=>
        string(1) "1"
        ["location"]=>
        array(2) {
          ["method"]=>
          string(3) "foo"
          ["type"]=>
          string(3) "Bar"
        }
      }
    }
  }
  ["message"]=>
  string(21) "[true]
[true]
[true]
"
}
