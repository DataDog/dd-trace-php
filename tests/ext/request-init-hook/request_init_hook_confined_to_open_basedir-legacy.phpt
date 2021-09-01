--TEST--
Request init hook is confined to open_basedir
--SKIPIF--
<?php if (PHP_VERSION_ID >= 70000) die('skip: Test does not work with internal spans'); ?>
--ENV--
DD_TRACE_DEBUG=1
--INI--
open_basedir=tests/ext/request-init-hook
ddtrace.request_init_hook={PWD}/../includes/sanity_check.php
--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECTF--
open_basedir restriction in effect; cannot open request init hook: '%s/sanity_check.php'
Request start
