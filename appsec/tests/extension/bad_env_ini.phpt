--TEST--
Bad env var setting is ignored
--ENV--
DD_APPSEC_LOG_LEVEL=bad
DD_APPSEC_ENABLED=1
--GET--
_force_cgi_sapi
--INI--
datadog.appsec.log_level=trace
extension=ddtrace.so
--FILE--
<?php
// should ignore env and prefer ini
var_dump(ini_get("datadog.appsec.log_level"));
?>
--EXPECTF--
Notice: %s: [ddappsec] Enabled not configured, computing enabled status in Unknown on line 0
string(5) "trace"

Notice: PHP Shutdown: [ddappsec] Shutting down the file logging in Unknown on line 0
