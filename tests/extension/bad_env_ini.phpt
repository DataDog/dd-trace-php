--TEST--
Bad env var setting results in hardcoded default fallback
--ENV--
DD_APPSEC_LOG_LEVEL=bad
--GET--
_force_cgi_sapi
--INI--
; ignored because overridden by environment
datadog.appsec.log_level=trace
--FILE--
<?php
// should be the hardcoded defaultl
var_dump(ini_get("datadog.appsec.log_level"));
?>
--EXPECTF--
string(4) "warn"
