--TEST--
Request init hook ignores exceptions
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
DD_AUTOLOAD_NO_COMPILE=1
--INI--
datadog.trace.sources_path="{PWD}/.."
--FILE--
<?php

class_exists('DDTrace\RaisesException');

echo "Request start" . PHP_EOL;

?>
--EXPECTF--
[ddtrace] [warning] Error raised in autoloaded file %s_files_api.php: %s(): Failed opening '%s_files_api.php' for inclusion %s on line %d
Throwing an exception...
[ddtrace] [warning] Exception thrown in autoloaded file %sRaisesException.php: Oops!
Request start
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
