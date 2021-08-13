--TEST--
Calling dd_init.php is confined to open_basedir settings
--SKIPIF--
<?php if (PHP_VERSION_ID >= 70000) die('skip: Test does not work with internal spans'); ?>
--ENV--
DD_TRACE_DEBUG=1
--INI--
open_basedir={PWD}
ddtrace.request_init_hook={PWD}/dd_init_open_basedir.inc
--FILE--
<?php
echo 'Done.' . PHP_EOL;
?>
--EXPECTF--
Calling dd_init.php from parent directory "%s/includes"
Error raised while opening request-init-hook stream: ddtrace_init(): open_basedir restriction in effect. File(%s/includes/dd_init.php) is not within the allowed path(s): (%s) in %s on line %d
Error opening request init hook: %s/dd_init.php
Done.
