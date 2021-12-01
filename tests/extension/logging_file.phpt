--TEST--
Logging to a specific file
--INI--
error_reporting=2147483647
ddappsec.log_file=/tmp/appseclog.txt
--FILE--
<?php
use function datadog\appsec\testing\mlog;
use const datadog\appsec\testing\log_level\WARNING;

mlog(WARNING, "warning message");

echo "Contents:\n";
echo file_get_contents('/tmp/appseclog.txt');

?>
--EXPECTF--
Contents:
%s[warning] warning message at %s
