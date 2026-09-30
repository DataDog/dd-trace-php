--TEST--
Setting 'datadog.log_backtrace' warns that it is unsupported where backtrace() is unavailable
--SKIPIF--
<?php
if (PHP_OS != "Linux") die('skip: Crashtracker/backtrace are only available on Linux');
if (getenv('DD_TRACE_CLI_ENABLED') === '0') die("skip: tracer is disabled");
if (!file_exists("/etc/os-release") || !preg_match("/alpine/i", file_get_contents("/etc/os-release"))) die("skip: requires musl");
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
[ddtrace] [warning] [%d] Setting 'datadog.log_backtrace' is not supported on this platform, as backtrace() is unavailable (e.g. on musl). Ignoring it.
1
