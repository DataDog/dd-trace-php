--TEST--
Calling dd_init.php is confined to open_basedir settings
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
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
[ddtrace] [warning] Error raised while opening autoloaded file stream for %s_files_tracer.php: %s(): open_basedir restriction in effect. File(%s_files_tracer.php) is not within the allowed path(s): (%sautoload-php-files) in %sdd_init_open_basedir.php on line %d
[ddtrace] [warning] Error opening autoloaded file %s_files_tracer.php
[ddtrace] [warning] Error raised while opening autoloaded file stream for %s../DDTrace/OpenBaseDir.php: %s(): open_basedir restriction in effect. File(%sDDTrace%cOpenBaseDir.php) is not within the allowed path(s): (%sautoload-php-files) in %sdd_init_open_basedir.php on line %d
[ddtrace] [warning] Error raised while opening autoloaded file stream for %s../api/OpenBaseDir.php: %s(): open_basedir restriction in effect. File(%s../api/OpenBaseDir.php) is not within the allowed path(s): (%sautoload-php-files) in %sdd_init_open_basedir.php on line %d
Done.
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
