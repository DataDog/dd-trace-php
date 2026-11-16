--TEST--
Set and test API security ini settings
--ENV--
DD_API_SECURITY_ENABLED=false
--FILE--
<?php
var_dump(ini_get("datadog.api_security_enabled"));
?>
--EXPECTF--
string(5) "false"
