--TEST--
Request init hook ignores fatal errors
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
DD_AUTOLOAD_NO_COMPILE=1
--INI--
datadog.trace.sources_path="{PWD}/.."
--FILE--
<?php

class_exists('DDTrace\RaisesFatalError');

echo "Request start" . PHP_EOL;

?>
--EXPECTF--
[ddtrace] [warning] [%d] Error raised in autoloaded file %s_files_api.php: %s(): Failed opening '%s_files_api.php' for inclusion %s on line %d
Calling a function that does not exist...
[ddtrace] [warning] [%d] Error raised in autoloaded file %s: Fatal error in autoloaded file in %s on line %d
Request start
[ddtrace] [info] [%d] Flushing v1 trace of size 1 to send-queue for %s
