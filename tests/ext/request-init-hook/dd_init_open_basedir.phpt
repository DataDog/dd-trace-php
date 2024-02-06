--TEST--
Calling dd_init.php is confined to open_basedir settings
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
--INI--
open_basedir={PWD}
ddtrace.request_init_hook={PWD}/dd_init_open_basedir.inc
--FILE--
<?php
echo 'Done.' . PHP_EOL;
?>
--EXPECTF--
Calling dd_init.php from parent directory "%s/includes"
[ddtrace] [warning] Error raised while opening request-init-hook stream: ddtrace_init(): open_basedir restriction in effect. File(%s/includes/dd_init.php) is not within the allowed path(s): (%s) in %s on line %d
[ddtrace] [warning] Error opening request init hook: %s/dd_init.php
Done.
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
