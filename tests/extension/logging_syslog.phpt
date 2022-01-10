--TEST--
Logging to syslog (basic test)
--INI--
error_reporting=2147483647
datadog.appsec.log_file=syslog
--FILE--
<?php
use function datadog\appsec\testing\mlog;
use const datadog\appsec\testing\log_level\WARNING;

mlog(WARNING, "warning message");

// check journalctl --user --reverse
?>
--EXPECT--
