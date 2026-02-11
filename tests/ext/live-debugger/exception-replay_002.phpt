--TEST--
Test exception replay capture limits
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_EXCEPTION_REPLAY_ENABLED=1
DD_EXCEPTION_REPLAY_CAPTURE_INTERVAL_SECONDS=1
--INI--
datadog.trace.agent_test_session_token=live-debugger/exception-replay_002
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

try {
    class MyClass {
        public $field1 = 1;
        public $field2 = 2;
        public $field3 = 3;
        public $field4 = 4;
        public $field5 = 5;
        public $field6 = 6;
        public $field7 = 7;
        public $field8 = 8;
        public $field9 = 9;
        public $field10 = 10;
        public $field11 = 11;
        public $field12 = 12;
        public $field13 = 13;
        public $field14 = 14;
        public $field15 = 15;
        public $field16 = 16;
        public $field17 = 17;
        public $field18 = 18;
        public $field19 = 19;
        public $field20 = 20;
        public $field21 = 21;

        function foo($foo) {
            $localArray = range(0, 100);
            $localStr = implode(" ", range(0, 100));
            throw new Exception("test");
        }
    }

    $obj = new stdClass;
    $obj->obj = $obj;
    (new MyClass)->foo($obj);
} catch (Exception $e) {
    $span = \DDTrace\start_span();
    $span->exception = $e;
    \DDTrace\close_span();
}

$dlr = new DebuggerLogReplayer;
$log = $dlr->waitForDebuggerDataAndReplay();
$log = json_decode($log["body"], true);

function recursive_ksort(&$arr) {
    if (is_array($arr)) {
        ksort($arr);
        array_walk($arr, 'recursive_ksort');
    }
}

recursive_ksort($log[0]["debugger"]["snapshot"]["captures"]);
var_dump($log[0]);

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECTF--
array(6) {
  ["service"]=>
  string(24) "exception-replay_002.php"
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
          array(2) {
            ["foo"]=>
            array(2) {
              ["fields"]=>
              array(1) {
                ["obj"]=>
                array(2) {
                  ["fields"]=>
                  array(1) {
                    ["obj"]=>
                    array(2) {
                      ["fields"]=>
                      array(1) {
                        ["obj"]=>
                        array(2) {
                          ["notCapturedReason"]=>
                          string(5) "depth"
                          ["type"]=>
                          string(8) "stdClass"
                        }
                      }
                      ["type"]=>
                      string(8) "stdClass"
                    }
                  }
                  ["type"]=>
                  string(8) "stdClass"
                }
              }
              ["type"]=>
              string(8) "stdClass"
            }
            ["this"]=>
            array(3) {
              ["fields"]=>
              array(20) {
                ["field10"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "10"
                }
                ["field11"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "11"
                }
                ["field12"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "12"
                }
                ["field13"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "13"
                }
                ["field14"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "14"
                }
                ["field15"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "15"
                }
                ["field16"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "16"
                }
                ["field17"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "17"
                }
                ["field18"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "18"
                }
                ["field19"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "19"
                }
                ["field2"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(1) "2"
                }
                ["field20"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "20"
                }
                ["field21"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "21"
                }
                ["field3"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(1) "3"
                }
                ["field4"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(1) "4"
                }
                ["field5"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(1) "5"
                }
                ["field6"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(1) "6"
                }
                ["field7"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(1) "7"
                }
                ["field8"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(1) "8"
                }
                ["field9"]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(1) "9"
                }
              }
              ["notCapturedReason"]=>
              string(10) "fieldCount"
              ["type"]=>
              string(7) "MyClass"
            }
          }
          ["locals"]=>
          array(2) {
            ["localArray"]=>
            array(3) {
              ["elements"]=>
              array(100) {
                [0]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(1) "0"
                }
                [1]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(1) "1"
                }
                [2]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(1) "2"
                }
                [3]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(1) "3"
                }
                [4]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(1) "4"
                }
                [5]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(1) "5"
                }
                [6]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(1) "6"
                }
                [7]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(1) "7"
                }
                [8]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(1) "8"
                }
                [9]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(1) "9"
                }
                [10]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "10"
                }
                [11]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "11"
                }
                [12]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "12"
                }
                [13]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "13"
                }
                [14]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "14"
                }
                [15]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "15"
                }
                [16]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "16"
                }
                [17]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "17"
                }
                [18]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "18"
                }
                [19]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "19"
                }
                [20]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "20"
                }
                [21]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "21"
                }
                [22]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "22"
                }
                [23]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "23"
                }
                [24]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "24"
                }
                [25]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "25"
                }
                [26]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "26"
                }
                [27]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "27"
                }
                [28]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "28"
                }
                [29]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "29"
                }
                [30]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "30"
                }
                [31]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "31"
                }
                [32]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "32"
                }
                [33]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "33"
                }
                [34]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "34"
                }
                [35]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "35"
                }
                [36]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "36"
                }
                [37]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "37"
                }
                [38]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "38"
                }
                [39]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "39"
                }
                [40]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "40"
                }
                [41]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "41"
                }
                [42]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "42"
                }
                [43]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "43"
                }
                [44]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "44"
                }
                [45]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "45"
                }
                [46]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "46"
                }
                [47]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "47"
                }
                [48]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "48"
                }
                [49]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "49"
                }
                [50]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "50"
                }
                [51]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "51"
                }
                [52]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "52"
                }
                [53]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "53"
                }
                [54]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "54"
                }
                [55]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "55"
                }
                [56]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "56"
                }
                [57]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "57"
                }
                [58]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "58"
                }
                [59]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "59"
                }
                [60]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "60"
                }
                [61]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "61"
                }
                [62]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "62"
                }
                [63]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "63"
                }
                [64]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "64"
                }
                [65]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "65"
                }
                [66]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "66"
                }
                [67]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "67"
                }
                [68]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "68"
                }
                [69]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "69"
                }
                [70]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "70"
                }
                [71]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "71"
                }
                [72]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "72"
                }
                [73]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "73"
                }
                [74]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "74"
                }
                [75]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "75"
                }
                [76]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "76"
                }
                [77]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "77"
                }
                [78]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "78"
                }
                [79]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "79"
                }
                [80]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "80"
                }
                [81]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "81"
                }
                [82]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "82"
                }
                [83]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "83"
                }
                [84]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "84"
                }
                [85]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "85"
                }
                [86]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "86"
                }
                [87]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "87"
                }
                [88]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "88"
                }
                [89]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "89"
                }
                [90]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "90"
                }
                [91]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "91"
                }
                [92]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "92"
                }
                [93]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "93"
                }
                [94]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "94"
                }
                [95]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "95"
                }
                [96]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "96"
                }
                [97]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "97"
                }
                [98]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "98"
                }
                [99]=>
                array(2) {
                  ["type"]=>
                  string(3) "int"
                  ["value"]=>
                  string(2) "99"
                }
              }
              ["notCapturedReason"]=>
              string(14) "collectionSize"
              ["type"]=>
              string(5) "array"
            }
            ["localStr"]=>
            array(4) {
              ["size"]=>
              string(3) "293"
              ["truncated"]=>
              bool(true)
              ["type"]=>
              string(6) "string"
              ["value"]=>
              string(255) "0 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30 31 32 33 34 35 36 37 38 39 40 41 42 43 44 45 46 47 48 49 50 51 52 53 54 55 56 57 58 59 60 61 62 63 64 65 66 67 68 69 70 71 72 73 74 75 76 77 78 79 80 81 82 83 84 85 86 87 8"
            }
          }
        }
      }
      ["probe"]=>
      array(2) {
        ["id"]=>
        string(0) ""
        ["location"]=>
        array(2) {
          ["method"]=>
          string(3) "foo"
          ["type"]=>
          string(7) "MyClass"
        }
      }
    }
  }
  ["message"]=>
  NULL
  ["process_tags"]=>
  NULL
}
