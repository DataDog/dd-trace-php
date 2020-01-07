--TEST--
Errors in request init hook do not affect error_get_last()
--ENV--
DD_TRACE_DEBUG=1
--INI--
error_reporting=E_ALL
ddtrace.request_init_hook=tests/ext/request-init-hook/raises_e_notice.php
--FILE--
<?php
var_dump(error_get_last());
?>
--EXPECTF--
Error raised in request init hook: Undefined variable: this_does_not_exist in %s on line %d
NULL
