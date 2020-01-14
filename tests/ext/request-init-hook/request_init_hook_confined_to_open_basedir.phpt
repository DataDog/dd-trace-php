--TEST--
Request init hook is confined to open_basedir
--ENV--
DD_TRACE_DEBUG=1
--INI--
open_basedir=tests/ext/request-init-hook
ddtrace.request_init_hook=tests/ext/includes/sanity_check.php
--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECTF--
Error raised while opening request-init-hook stream: Unknown: open_basedir restriction in effect. File(%s) is not within the allowed path(s): (%s) in Unknown on line 0
Error opening request init hook: %s
Request start
