--TEST--
Set and test API security ini settings
--ENV--
DD_API_SECURITY_ENABLED=false
DD_API_SECURITY_REQUEST_SAMPLE_RATE=0.8
--FILE--
<?php
var_dump(ini_get("datadog.api_security_enabled"));
var_dump(ini_get("datadog.api_security_request_sample_rate"))
?>
--EXPECTF--
string(5) "false"
string(3) "0.8"
