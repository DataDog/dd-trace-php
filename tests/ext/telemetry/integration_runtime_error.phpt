--TEST--
Send logs from integration
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
DD_TELEMETRY_LOG_COLLECTION_ENABLED=1
DD_TRACE_LOG_LEVEL=warn
--INI--
datadog.trace.agent_url="file://{PWD}/integration-runtime-error-telemetry.out"
--FILE--
<?php

function foo() {
    echo "foo\n";
}

DDTrace\trace_function('foo', function (\DDTrace\SpanData $span) {
    $span->name = 'foo';
    throw new Exception("test");
});

DDTrace\trace_function('foo', function (\DDTrace\SpanData $span) {
    trigger_error("Testnotice", E_USER_NOTICE);
});

foo();
foo();

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
foo
[ddtrace] [warning] Error raised in ddtrace's closure defined at %sintegration_runtime_error.php:12 for foo(): Testnotice in %sintegration_runtime_error.php on line 13
[ddtrace] [warning] Exception thrown in ddtrace's closure defined at %sintegration_runtime_error.php:7 for foo(): test
foo
[ddtrace] [warning] Error raised in ddtrace's closure defined at %sintegration_runtime_error.php:12 for foo(): Testnotice in %sintegration_runtime_error.php on line 13
[ddtrace] [warning] Exception thrown in ddtrace's closure defined at %sintegration_runtime_error.php:7 for foo(): test
array(2) {
  [0]=>
  array(6) {
    ["message"]=>
    string(165) "Error raised in ddtrace's closure defined at <redacted>%cintegration_runtime_error.php:12 for foo(): Testnotice in <redacted>%cintegration_runtime_error.php on line 13"
    ["level"]=>
    string(4) "WARN"
    ["count"]=>
    int(2)
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
    string(107) "Exception thrown in ddtrace's closure defined at <redacted>%cintegration_runtime_error.php:7 for foo(): test"
    ["level"]=>
    string(4) "WARN"
    ["count"]=>
    int(2)
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
