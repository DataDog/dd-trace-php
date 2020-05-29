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
--EXPECT--
open_basedir restriction in effect; cannot open request init hook: 'tests/ext/includes/sanity_check.php'
Request start
