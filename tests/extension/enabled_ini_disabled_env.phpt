--TEST--
Conflict between DD_APPSEC_ENABLED and ddappsec.enabled
--ENV--
DD_APPSEC_ENABLED=false
--GET--
_force_cgi_sapi
--INI--
ddappsec.enabled=true
--FILE--
<?php
var_dump(ini_get("ddappsec.enabled"));
?>
--EXPECTF--
string(1) "1"
