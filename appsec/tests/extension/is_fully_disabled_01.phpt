--TEST--
is_fully_disabled returns true when DD_APPSEC_ENABLED=0 (fully disabled)
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
--ENV--
DD_APPSEC_ENABLED=0
--FILE--
<?php
var_dump(\datadog\appsec\is_fully_disabled());
?>
--EXPECT--
bool(true)
