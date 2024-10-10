--TEST--
Errors in ddtrace autoloader do not affect error_get_last()
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
DD_AUTOLOAD_NO_COMPILE=1
DD_APPSEC_ENABLED=0
--INI--
error_reporting=E_ALL
datadog.trace.sources_path="{PWD}/.."
--FILE--
<?php
class_exists('DDTrace\RaisesNotice');
var_dump(error_get_last());
?>
--EXPECTF--
[ddtrace] [warning] Error raised in autoloaded file %s_files_api.php: %s(): Failed opening '%s_files_api.php' for inclusion %s on line %d
[ddtrace] [warning] Error raised in autoloaded file %sRaisesNotice.php: Notice? in %s on line %d
NULL
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
