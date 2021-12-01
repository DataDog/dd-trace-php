--TEST--
Defaults logging to php with level warn
--INI--
error_reporting=2147483647
--FILE--
<?php
use function datadog\appsec\testing\mlog;
use const datadog\appsec\testing\log_level\{FATAL,ERROR,WARNING,INFO,DEBUG,TRACE};
mlog(FATAL, "fatal message");
mlog(ERROR, "error message");
mlog(WARNING, "warning message");
mlog(INFO, "info message");
mlog(DEBUG, "debug message");
mlog(TRACE, "trace message");
?>
--EXPECTF--
Warning: datadog\appsec\testing\mlog(): [ddappsec] fatal message in %s on line %d

Warning: datadog\appsec\testing\mlog(): [ddappsec] error message in %s on line %d

Warning: datadog\appsec\testing\mlog(): [ddappsec] warning message in %s on line %d
