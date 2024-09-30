--TEST--
The tracer will ignore incompatible extensions
--SKIPIF--
<?php if (PHP_VERSION_ID < 70100) die('skip: PHP 7.1+ required'); ?>
--INI--
xdebug.remote_enable=1
datadog.inject_force=1
datadog.trace.log_level=warn
--FILE--
<?php
if (!extension_loaded('Xdebug') || version_compare(phpversion('Xdebug'), '2.9.5') >= 0) die('Xdebug < 2.9.5 required');

echo 'Done.' . PHP_EOL;
?>
--EXPECTF--
[ddtrace] [warning] Found incompatible Xdebug version %s; ddtrace requires Xdebug 2.9.5 or greater
[ddtrace] [warning] Found incompatible extension(s); ignoring since 'datadog.inject_force' is enabled
Done.
