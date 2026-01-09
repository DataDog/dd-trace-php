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
Notice: %s: [ddappsec] Skipping automatic request init in testing in Unknown on line %d
string(5) "trace"

Notice: %s: [ddappsec] Skipping automatic request shutdown in testing in Unknown on line %d

Notice: PHP Startup: [ddappsec] Request lifecycle matches PHP's in Unknown on line %d

Notice: PHP Startup: [ddappsec] Successfully initialized static libxml2 in Unknown on line %d

Notice: PHP Shutdown: [ddappsec] Shutting down the file logging in Unknown on line %d
