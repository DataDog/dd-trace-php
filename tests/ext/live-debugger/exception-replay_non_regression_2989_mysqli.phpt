--TEST--
Non regression test for use-after-free segfault in exception replay
--SKIPIF--
<?php if (!extension_loaded('mysqli')) die('skip: mysqli extension required'); ?>
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support %A in EXPECTF"); ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_EXCEPTION_REPLAY_ENABLED=1
DD_EXCEPTION_REPLAY_CAPTURE_INTERVAL_SECONDS=1
--INI--
datadog.trace.agent_test_session_token=live-debugger/non_regression_2989_mysqli
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $mysqli = new mysqli("@@_INVALID_@@", "foo", "bar");
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
Warning: mysqli::__construct(): php_network_getaddresses: getaddrinfo %s
%Aarray(5) {
  ["service"]=>
  string(47) "exception-replay_non_regression_2989_mysqli.php"
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
      string(16) "%s"
      ["frameIndex"]=>
      int(0)
      ["captures"]=>
      array(1) {
        ["return"]=>
        array(1) {
          ["arguments"]=>
          array(4) {
            ["hos%s"]=>
            array(2) {
              ["type"]=>
              string(6) "string"
              ["value"]=>
              string(13) "@@_INVALID_@@"
            }
            ["password"]=>
            array(%d) {
%A
            }
            ["this"]=>
            array(2) {
              ["fields"]=>
              array(%d) {
%A
              }
              ["type"]=>
              string(6) "mysqli"
            }
            ["use%s"]=>
            array(2) {
              ["type"]=>
              string(6) "string"
              ["value"]=>
              string(3) "foo"
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
          string(11) "__construct"
          ["type"]=>
          string(6) "mysqli"
        }
      }
    }
  }
  ["message"]=>
  NULL
}
