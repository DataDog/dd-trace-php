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
bool(false)