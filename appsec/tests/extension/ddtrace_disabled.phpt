--TEST--
When  ddtrace module no loaded, appsec is disabled
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
datadog.appsec.testing=0
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
var_dump(\datadog\appsec\is_enabled());
?>
--EXPECTF--
Warning: PHP Startup: [ddappsec] Failed to load ddtrace_ip_extraction_find: %s undefined symbol: ddtrace_ip_extraction_find in Unknown on line %d
bool(false)
