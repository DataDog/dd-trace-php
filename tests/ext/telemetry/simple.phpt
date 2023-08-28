--TEST--
Simple telemetry test
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}");
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip timing sensitive test - valgrind is too slow');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
--INI--
datadog.trace.agent_url="file://{PWD}/simple-telemetry.out"
--FILE--
<?php

DDTrace\start_span();
DDTrace\close_span();

dd_trace_internal_fn("finalize_telemetry");

for ($i = 0; $i < 100; ++$i) {
    usleep(100000);
    if (file_exists(__DIR__ . '/simple-telemetry.out')) {
        $batches = [];
        foreach (file(__DIR__ . '/simple-telemetry.out') as $l) {
            if ($l) {
                $json = json_decode($l, true);
                array_push($batches, ...($json["request_type"] == "message-batch" ? $json["payload"] : [$json]));
            }
        }
        $found = array_filter($batches, function ($json) {
            return $json["request_type"] == "app-started" || $json["request_type"] == "app-closing";
        });
        if (count($found) == 2) {
            foreach ($found as $json) {
                var_dump($json["request_type"]);
            }
            break;
        }
    }
}

?>
--EXPECT--
string(11) "app-started"
string(11) "app-closing"
--CLEAN--
<?php

@unlink(__DIR__ . '/simple-telemetry.out');
