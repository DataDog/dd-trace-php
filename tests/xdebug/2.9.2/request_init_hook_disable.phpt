--TEST--
The tracer will disable itself with older versions of Xdebug
--SKIPIF--
<?php if (PHP_VERSION_ID < 70100) die('skip: PHP 7.1+ required'); ?>
--INI--
xdebug.remote_enable=1
ddtrace.request_init_hook={PWD}/../fake_request_init_hook.inc
--FILE--
<?php
if (!extension_loaded('Xdebug') || version_compare(phpversion('Xdebug'), '2.9.5') >= 0) die('Xdebug < 2.9.5 required');

echo 'Done.' . PHP_EOL;
?>
--EXPECTF--
Found incompatible Xdebug version %s; ddtrace requires Xdebug 2.9.5 or greater; disabling conflicting functionality
Done.
