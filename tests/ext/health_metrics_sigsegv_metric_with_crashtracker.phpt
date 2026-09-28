--TEST--
Health metrics do not report segmentation faults while the crashtracker is enabled
--SKIPIF--
<?php
if (PHP_OS != "Linux") die('skip: Crashtracker/backtrace are only available on Linux');
if (getenv('DD_TRACE_CLI_ENABLED') === '0') die("skip: tracer is disabled");
?>
--ENV--
DD_TRACE_LOG_LEVEL=warn,span=off,startup=off
DD_TRACE_HEALTH_METRICS_ENABLED=1
DD_DOGSTATSD_URL=udp://127.0.0.1:8125
DD_CRASHTRACKING_ENABLED=1
--INI--
datadog.trace.log_file=file://stdout
--FILE--
<?php

print_r(1);

?>
--EXPECTF--
[ddtrace] [warning] [%d] Segmentation faults will not be reported as the 'datadog.tracer.uncaught_exceptions' health metric while 'datadog.crashtracking_enabled' is on.
1
