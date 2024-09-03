--TEST--
Calling dd_init.php is confined to open_basedir settings
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off,datadog_sidecar=off
DD_AUTOLOAD_NO_COMPILE=1
--INI--
open_basedir="{PWD}"
datadog.trace.sources_path="{PWD}/.."
--FILE--
<?php
spl_autoload_register(function() {}); // silence warning from default autoloader on PHP 7
class_exists('DDTrace\OpenBaseDir');
echo 'Done.' . PHP_EOL;
?>
--EXPECTF--
[ddtrace] [warning] Error raised in autoloaded file %s_files_api.php: %s(): Failed opening '%s_files_api.php' for inclusion %s on line %d
[ddtrace] [warning] Error raised in autoloaded file %s_files_tracer.php: %s(): Failed opening '%s_files_tracer.php' for inclusion %s on line %d
[ddtrace] [warning] Error raised in autoloaded file %sDDTrace/OpenBaseDir.php: %s(): Failed opening '%sDDTrace/OpenBaseDir.php' for inclusion %s on line %d
Done.
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
