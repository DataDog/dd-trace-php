--TEST--
Do not fail when PHP code couldn't be loaded
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
DD_AUTOLOAD_NO_COMPILE=1
--INI--
datadog.trace.sources_path="{PWD}/does-not-exist"
--FILE--
<?php

class_exists('DDTrace\Invalid');

echo "Request start" . PHP_EOL;

?>
--EXPECTF--
[ddtrace] [warning] Error raised in autoloaded file %s_files_api.php: %s(): Failed opening '%s_files_api.php' for inclusion %s on line %d
[ddtrace] [warning] Error raised in autoloaded file %s_files_tracer.php: %s(): Failed opening '%s_files_tracer.php' for inclusion %s on line %d
Request start
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
