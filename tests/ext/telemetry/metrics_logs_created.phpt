--TEST--
'logs_created' internal metric
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}");
if (PHP_OS === "WINNT" && PHP_VERSION_ID < 70400) die("skip: Windows on PHP 7.2 and 7.3 have permission issues with synchronous access to telemetry");
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip timing sensitive test - valgrind is too slow');
require __DIR__ . '/../includes/clear_skipif_telemetry.inc'
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
DD_TRACE_LOG_LEVEL=warn
--INI--
datadog.trace.agent_url="file://{PWD}/metrics-logs-created-telemetry.out"
--FILE--
<?php

dd_trace_internal_fn("test_logs");

dd_trace_internal_fn("finalize_telemetry");

for ($i = 0; $i < 100; ++$i) {
    usleep(100000);
    if (file_exists(__DIR__ . '/metrics-logs-created-telemetry.out')) {
        foreach (file(__DIR__ . '/metrics-logs-created-telemetry.out') as $l) {
            if ($l) {
                $json = json_decode($l, true);
                $batch = $json["request_type"] == "message-batch" ? $json["payload"] : [$json];
                foreach ($batch as $json) {
                    if ($json["request_type"] == "generate-metrics") {
                        $series = [];
                        foreach ($json['payload']['series'] as $serie) {
                            if ($serie['metric'] !== 'logs_created') {
                              continue;
                            }
                            $key = $serie['namespace'].$serie['metric'].implode(',', $serie['tags']);
                            $series[$key] = $serie;
                        };
                        ksort($series);
                        var_dump(array_values($series));

                        break 3;
                    }
                }
            }
        }
    }
}

?>
--EXPECTF--
[ddtrace] [warning] foo
[ddtrace] [warning] bar
[ddtrace] [error] Boum
array(2) {
  [0]=>
  array(7) {
    ["namespace"]=>
    string(7) "general"
    ["metric"]=>
    string(12) "logs_created"
    ["points"]=>
    array(1) {
      [0]=>
      array(2) {
        [0]=>
        int(%d)
        [1]=>
        float(1)
      }
    }
    ["tags"]=>
    array(1) {
      [0]=>
      string(11) "level:error"
    }
    ["common"]=>
    bool(true)
    ["type"]=>
    string(5) "count"
    ["interval"]=>
    int(10)
  }
  [1]=>
  array(7) {
    ["namespace"]=>
    string(7) "general"
    ["metric"]=>
    string(12) "logs_created"
    ["points"]=>
    array(1) {
      [0]=>
      array(2) {
        [0]=>
        int(%d)
        [1]=>
        float(2)
      }
    }
    ["tags"]=>
    array(1) {
      [0]=>
      string(10) "level:warn"
    }
    ["common"]=>
    bool(true)
    ["type"]=>
    string(5) "count"
    ["interval"]=>
    int(10)
  }
}
--CLEAN--
<?php

@unlink(__DIR__ . '/metrics-logs-created-telemetry.out');
