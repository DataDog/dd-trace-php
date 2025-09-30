--TEST--
is_fully_disabled returns false when DD_APPSEC_ENABLED=1 (not fully disabled)
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
var_dump(\datadog\appsec\is_fully_disabled());
?>
--EXPECT--
bool(false)
