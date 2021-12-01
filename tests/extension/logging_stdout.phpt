--TEST--
Logging to stdout
--SKIPIF--
<?php
if (key_exists('USE_ZEND_ALLOC', $_ENV) && $_ENV['USE_ZEND_ALLOC'] == '0') {
    die('skip not to run with valgrind'); // probably PHP errors after it can't emit the message (stdout closed)
}
?>
--INI--
error_reporting=2147483647
ddappsec.log_file=stdout
--FILE--
<?php
use function datadog\appsec\testing\{mlog,fdclose};
use const datadog\appsec\testing\log_level\WARNING;

mlog(WARNING, "warning message");

fdclose(1);
mlog(WARNING, "should not be printable");
?>
--EXPECTF--
%s[warning] warning message at %s
