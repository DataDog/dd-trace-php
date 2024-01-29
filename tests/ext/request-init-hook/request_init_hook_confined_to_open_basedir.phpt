--TEST--
Request init hook is confined to open_basedir
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
--INI--
open_basedir=tests/ext/request-init-hook
ddtrace.request_init_hook={PWD}/../includes/sanity_check.php
--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECTF--
[ddtrace] [warning] open_basedir restriction in effect; cannot open request init hook: '%s/sanity_check.php'
Request start
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
