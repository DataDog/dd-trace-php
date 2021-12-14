--TEST--
Conflict between DD_APPSEC_ENABLED and datadog.appsec.enabled
--ENV--
DD_APPSEC_ENABLED=false
--GET--
_force_cgi_sapi
--INI--
datadog.appsec.enabled=true
--FILE--
<?php
var_dump(ini_get("datadog.appsec.enabled"));
?>
--EXPECTF--
string(1) "1"
