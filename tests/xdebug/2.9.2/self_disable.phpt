--TEST--
The tracer will disable itself with older versions of Xdebug
--SKIPIF--
<?php if (PHP_VERSION_ID < 70100) die('skip: PHP 7.1+ required'); ?>
--INI--
xdebug.remote_enable=1
datadog.trace.sidecar_trace_sender=0
--FILE--
<?php
if (!extension_loaded('Xdebug') || version_compare(phpversion('Xdebug'), '2.9.5') >= 0) die('Xdebug < 2.9.5 required');

var_dump(dd_trace_internal_fn('set_writer_send_on_flush', false));
echo 'Done.' . PHP_EOL;
?>
--EXPECTF--
[ddtrace] [error] [%d] Found incompatible Xdebug version %s; ddtrace requires Xdebug 2.9.5 or greater
[ddtrace] [error] [%d] Found incompatible extension(s); disabling conflicting functionality
bool(false)
Done.
