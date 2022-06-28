--TEST--
Shebang should not affect line numbers
--INI--
ddtrace.request_init_hook={PWD}/../includes/request_init_hook.inc
--FILE--
#!php
<?php

error_reporting(E_ALL);

echo $foo; // Should be line 6 in error message

?>
--EXPECTF--
Calling ddtrace_init()...
Called dd_init.php

%s: Undefined variable%sfoo in %s on line 6
