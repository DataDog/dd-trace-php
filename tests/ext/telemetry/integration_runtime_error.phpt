--TEST--
Send logs from integration
--SKIPIF--
<?php
if (!extension_loaded('curl')) die('skip: curl extension required');
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}");
if (PHP_OS === "WINNT" && PHP_VERSION_ID < 70400) die("skip: Windows on PHP 7.2 and 7.3 have permission issues with synchronous access to telemetry");
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip timing sensitive test - valgrind is too slow');
require __DIR__ . '/../includes/clear_skipif_telemetry.inc'
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
DD_TELEMETRY_LOG_COLLECTION_ENABLED=1
DD_TRACE_LOG_LEVEL=warn
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=curl_exec
--INI--
datadog.trace.agent_url="file://{PWD}/integration-runtime-error-telemetry.out"
--FILE--
<?php
include 'curl_helper.inc';

DDTrace\trace_function('curl_exec', function (\DDTrace\SpanData $span) {
    $span->name = 'curl_exec';
    5/0;
});

DDTrace\trace_function('curl_setopt', function (\DDTrace\SpanData $span) {
    $span->name = $a;
});

$ch = curl_init('https://raw.githubusercontent.com/DataDog/dd-trace-php/master/README.md');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_exec($ch);
curl_exec($ch);
curl_exec($ch);

dd_trace_internal_fn("finalize_telemetry");

for ($i = 0; $i < 100; ++$i) {
    usleep(100000);
    if (file_exists(__DIR__ . '/integration-runtime-error-telemetry.out')) {
        foreach (file(__DIR__ . '/integration-runtime-error-telemetry.out') as $l) {
            if ($l) {
                $json = json_decode($l, true);
                $batch = $json["request_type"] == "message-batch" ? $json["payload"] : [$json];
                foreach ($batch as $json) {
                    if ($json["request_type"] == "logs") {
                        $logs = $json['payload'];
                        ksort($logs);
                        var_dump(array_values($logs));

                        break 3;
                    }
                }
            }
        }
    }
}

?>
--EXPECTF--
[ddtrace] [warning] Error raised in ddtrace's closure defined at /home/circleci/app/tmp/build_extension/tests/ext/telemetry/integration_runtime_error.php:9 for curl_setopt(): Undefined variable $a in /home/circleci/app/tmp/build_extension/tests/ext/telemetry/integration_runtime_error.php on line 10
[ddtrace] [warning] DivisionByZeroError thrown in ddtrace's closure defined at /home/circleci/app/tmp/build_extension/tests/ext/telemetry/integration_runtime_error.php:4 for curl_exec(): Division by zero
[ddtrace] [warning] DivisionByZeroError thrown in ddtrace's closure defined at /home/circleci/app/tmp/build_extension/tests/ext/telemetry/integration_runtime_error.php:4 for curl_exec(): Division by zero
[ddtrace] [warning] DivisionByZeroError thrown in ddtrace's closure defined at /home/circleci/app/tmp/build_extension/tests/ext/telemetry/integration_runtime_error.php:4 for curl_exec(): Division by zero
array(2) {
  [0]=>
  array(6) {
    ["message"]=>
    string(183) "Error raised in ddtrace's closure defined at <redacted>/integration_runtime_error.php:9 for curl_setopt(): Undefined variable $a in <redacted>/integration_runtime_error.php on line 10"
    ["level"]=>
    string(4) "WARN"
    ["count"]=>
    int(1)
    ["stack_trace"]=>
    NULL
    ["tags"]=>
    string(0) ""
    ["is_sensitive"]=>
    bool(false)
  }
  [1]=>
  array(6) {
    ["message"]=>
    string(135) "DivisionByZeroError thrown in ddtrace's closure defined at <redacted>/integration_runtime_error.php:4 for curl_exec(): Division by zero"
    ["level"]=>
    string(4) "WARN"
    ["count"]=>
    int(3)
    ["stack_trace"]=>
    NULL
    ["tags"]=>
    string(0) ""
    ["is_sensitive"]=>
    bool(false)
  }
}

--CLEAN--
<?php

@unlink(__DIR__ . '/integration-runtime-error-telemetry.out');
