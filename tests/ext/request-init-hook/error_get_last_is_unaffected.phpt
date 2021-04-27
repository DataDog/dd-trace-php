--TEST--
Errors in request init hook do not affect error_get_last()
--ENV--
DD_TRACE_DEBUG=1
--INI--
error_reporting=E_ALL
ddtrace.request_init_hook={PWD}/raises_e_notice.php
--FILE--
<?php
var_dump(error_get_last());
?>
--EXPECTF--
%s in request init hook: Undefined variable%sthis_does_not_%s
NULL
