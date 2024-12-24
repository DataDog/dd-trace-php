--TEST--
Filesystem integration can also be disabled with default integrations flag
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}");
if (PHP_OS === "WINNT" && PHP_VERSION_ID < 70400) die("skip: Windows on PHP 7.2 and 7.3 have permission issues with synchronous access to telemetry");
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip timing sensitive test - valgrind is too slow');
require __DIR__ . '/../includes/clear_skipif_telemetry.inc'
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
_DD_LOAD_TEST_INTEGRATIONS=1
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
DD_APPSEC_RASP_ENABLED=1
DD_TRACE_FILESYSTEM_ENABLED=0
--INI--
datadog.trace.agent_url="file://{PWD}/integration-telemetry-04.out"
--FILE--
<?php
namespace
{
    $file = ini_get('datadog.trace.agent_url');
    dd_trace_internal_fn("finalize_telemetry");

    for ($i = 0; $i < 100; ++$i) {
        usleep(100000);
        if (file_exists($file )) {
            foreach (file($file) as $l) {
                if ($l) {
                    $json = json_decode($l, true);
                    $batch = $json["request_type"] == "message-batch" ? $json["payload"] : [$json];
                    foreach ($batch as $json) {
                        if ($json["request_type"] == "app-integrations-change") {
                            var_dump($json["payload"]);
                            break 3;
                        }
                    }
                }
            }
        }
    }
}

?>
--EXPECT--
array(1) {
  ["integrations"]=>
  array(2) {
    [0]=>
    array(5) {
      ["name"]=>
      string(10) "filesystem"
      ["enabled"]=>
      bool(false)
      ["version"]=>
      string(0) ""
      ["compatible"]=>
      NULL
      ["auto_enabled"]=>
      NULL
    }
    [1]=>
    array(5) {
      ["name"]=>
      string(4) "logs"
      ["enabled"]=>
      bool(false)
      ["version"]=>
      string(0) ""
      ["compatible"]=>
      NULL
      ["auto_enabled"]=>
      NULL
    }
  }
}
--CLEAN--
<?php

@unlink(ini_get('datadog.trace.agent_url'));
