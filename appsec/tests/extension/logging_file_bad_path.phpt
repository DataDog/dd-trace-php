--TEST--
Logging to a specific file: bad path
--INI--
error_reporting=2147483647
datadog.appsec.log_file=/bad/path/appseclog.txt
--FILE--
<?php
use function datadog\appsec\testing\mlog;
use const datadog\appsec\testing\log_level\WARNING;

mlog(WARNING, "warning message");

?>
--EXPECTF--
Warning: datadog\appsec\testing\mlog(): [ddappsec] Error opening log file '/bad/path/appseclog.txt' (errno 2: No such file or directory) in %s on line %d

Warning: datadog\appsec\testing\mlog(): [ddappsec] Could not initialize logging in %s on line %d
