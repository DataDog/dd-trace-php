--TEST--
The tracer will disable itself with Xdebug on PHP 7.0
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000 || PHP_VERSION_ID >= 70100) die('skip: PHP 7.0 required'); ?>
--INI--
xdebug.remote_enable=1
--FILE--
<?php
if (!extension_loaded('Xdebug')) die('skip: Xdebug required');

echo 'Done.' . PHP_EOL;
?>
--EXPECTF--
[ddtrace] [error] Found incompatible Xdebug version %s
[ddtrace] [error] Found incompatible extension(s); disabling conflicting functionality
Done.
