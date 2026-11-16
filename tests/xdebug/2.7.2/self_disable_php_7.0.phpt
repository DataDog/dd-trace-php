--TEST--
The tracer will disable itself with Xdebug on PHP 7.0
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000 || PHP_VERSION_ID >= 70100) die('skip: PHP 7.0 required'); ?>
--INI--
xdebug.remote_enable=1
datadog.trace.sidecar_trace_sender=0
--FILE--
<?php
if (!extension_loaded('Xdebug')) die('skip: Xdebug required');

var_dump(dd_trace_internal_fn('set_writer_send_on_flush', false));
echo 'Done.' . PHP_EOL;
?>
--EXPECTF--
[ddtrace] [error] [%d] Found incompatible Xdebug version %s
[ddtrace] [error] [%d] Found incompatible extension(s); disabling conflicting functionality
bool(false)
Done.
