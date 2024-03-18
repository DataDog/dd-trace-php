--TEST--
Disabled telemetry test
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}");
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
--INI--
datadog.trace.agent_url="file://{PWD}/disabled-telemetry.out"
--FILE--
<?php

DDTrace\start_span();
DDTrace\close_span();

dd_trace_internal_fn("finalize_telemetry");

usleep(100000);
var_dump(file_exists(__DIR__ . '/disabled-telemetry.out'));

?>
--EXPECT--
bool(false)
