--TEST--
Request init hook is confined to open_basedir
--INI--
open_basedir=tests/ext/request-init-hook
ddtrace.request_init_hook=tests/ext/includes/sanity_check.php
--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECT--
Request start
