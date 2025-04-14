--TEST--
Read telemetry via composer
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
--INI--
datadog.trace.agent_url="file://{PWD}/composer-telemetry.out"
--FILE--
<?php

DDTrace\start_span();

include __DIR__ . '/vendor/autoload.php';

DDTrace\close_span();

dd_trace_internal_fn("finalize_telemetry");

for ($i = 0; $i < 300; ++$i) {
    usleep(100000);
    if (file_exists(__DIR__ . '/composer-telemetry.out')) {
        foreach (file(__DIR__ . '/composer-telemetry.out') as $l) {
            if ($l) {
                $json = json_decode($l, true);
                $batch = $json["request_type"] == "message-batch" ? $json["payload"] : [$json];
                foreach ($batch as $json) {
                    if ($json["request_type"] == "app-dependencies-loaded") {
                        print_r($json["payload"]);
                        break 3;
                    }
                }
            }
        }
    }
}

?>
--EXPECTF--
Included
Array
(
    [dependencies] => Array
        (
            [0] => Array
                (
                    [name] => datadog/dd-trace
                    [version] => dev-master
                )

            [1] => Array
                (
                    [name] => ext-Core
                    [version] => %s
                )
%a
        )

)
--CLEAN--
<?php

@unlink(__DIR__ . '/composer-telemetry.out');
