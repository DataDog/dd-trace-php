--TEST--
Settings 'datadog.log_backtrace' and 'datadog.crashtracking_enabled' are mutually exclusive
--SKIPIF--
<?php
if (PHP_OS != "Linux") die('skip: Crashtracker/backtrace are only available on Linux');
if (getenv('DD_TRACE_CLI_ENABLED') === '0') die("skip: tracer is disabled");
?>
--ENV--
DD_TRACE_LOG_LEVEL=warn,span=off,startup=off
DD_LOG_BACKTRACE=1
DD_CRASHTRACKING_ENABLED=1
--INI--
datadog.trace.log_file=file://stdout
--FILE--
<?php

print_r(1);

?>
--EXPECTF--
[ddtrace] [warning] Settings 'datadog.log_backtrace' and 'datadog.crashtracking_enabled' are mutually exclusive. Cannot enable the backtrace.
1
