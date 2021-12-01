--TEST--
Logging with PHP error reporting
--INI--
error_reporting=2147483647
ddappsec.log_file=php_error_reporting
--FILE--
<?php
use function datadog\appsec\testing\mlog;
use const datadog\appsec\testing\log_level\{OFF,FATAL,ERROR,WARNING,INFO,DEBUG,TRACE};

function print_all_levels() {
    mlog(FATAL, "fatal message");
    mlog(ERROR, "error message");
    mlog(WARNING, "warning message");
    mlog(INFO, "info message");
    mlog(DEBUG, "debug message");
    mlog(TRACE, "trace message");
}

echo "All levels\n";
ini_set('ddappsec.log_level', 'trace');
print_all_levels();

echo "Debug\n";
ini_set('ddappsec.log_level', 'debug');
print_all_levels();

echo "Info\n";
ini_set('ddappsec.log_level', 'info');
print_all_levels();

echo "Warning\n";
ini_set('ddappsec.log_level', 'warning');
print_all_levels();

echo "Error\n";
ini_set('ddappsec.log_level', 'error');
print_all_levels();

echo "Fatal\n";
ini_set('ddappsec.log_level', 'fatal');
print_all_levels();

echo "OFF\n";
ini_set('ddappsec.log_level', 'off');
print_all_levels();

?>
--EXPECTF--
All levels

Warning: datadog\appsec\testing\mlog(): [ddappsec] fatal message in %s on line %d

Warning: datadog\appsec\testing\mlog(): [ddappsec] error message in %s on line %d

Warning: datadog\appsec\testing\mlog(): [ddappsec] warning message in %s on line %d

Notice: datadog\appsec\testing\mlog(): [ddappsec] info message in %s on line %d

Notice: datadog\appsec\testing\mlog(): [ddappsec] debug message in %s on line %d

Notice: datadog\appsec\testing\mlog(): [ddappsec] trace message in %s on line %d
Debug

Warning: datadog\appsec\testing\mlog(): [ddappsec] fatal message in %s on line %d

Warning: datadog\appsec\testing\mlog(): [ddappsec] error message in %s on line %d

Warning: datadog\appsec\testing\mlog(): [ddappsec] warning message in %s on line %d

Notice: datadog\appsec\testing\mlog(): [ddappsec] info message in %s on line %d

Notice: datadog\appsec\testing\mlog(): [ddappsec] debug message in %s on line %d
Info

Warning: datadog\appsec\testing\mlog(): [ddappsec] fatal message in %s on line %d

Warning: datadog\appsec\testing\mlog(): [ddappsec] error message in %s on line %d

Warning: datadog\appsec\testing\mlog(): [ddappsec] warning message in %s on line %d

Notice: datadog\appsec\testing\mlog(): [ddappsec] info message in %s on line %d
Warning

Warning: datadog\appsec\testing\mlog(): [ddappsec] fatal message in %s on line %d

Warning: datadog\appsec\testing\mlog(): [ddappsec] error message in %s on line %d

Warning: datadog\appsec\testing\mlog(): [ddappsec] warning message in %s on line %d
Error

Warning: datadog\appsec\testing\mlog(): [ddappsec] fatal message in %s on line %d

Warning: datadog\appsec\testing\mlog(): [ddappsec] error message in %s on line %d
Fatal

Warning: datadog\appsec\testing\mlog(): [ddappsec] fatal message in %s on line %d
OFF
