--TEST--
Logging with PHP error reporting: user error handler is ignored
--INI--
error_reporting=2147483647
ddappsec.log_file=php_error_reporting
--FILE--
<?php
use function datadog\appsec\testing\mlog;
use const datadog\appsec\testing\log_level\WARNING;

set_error_handler(function($sev, $msg, $file, $line, $ctx) {
    die('Should not be reached');
});

mlog(WARNING, "warning message");

?>
--EXPECTF--
Warning: datadog\appsec\testing\mlog(): [ddappsec] warning message in %s on line %d
