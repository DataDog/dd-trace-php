--TEST--
Request abort (request init variant)
--SKIPIF--
<?php
require __DIR__ . "/inc/no_valgrind.php";
?>
--INI--
datadog.appsec.testing_abort_rinit=1
datadog.appsec.enabled=1
--FILE--
THIS SHOULD NOT BE REACHED
--EXPECTHEADERS--
Status: 403 Forbidden
--EXPECTF--
%s
Warning: %s: Datadog blocked the request and presented a static error page in Unknown on line %d
