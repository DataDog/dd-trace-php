--TEST--
is_fully_disabled returns true when DD_APPSEC_ENABLED not set and remote config disabled (fully disabled)
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
--ENV--
DD_REMOTE_CONFIG_ENABLED=0
--FILE--
<?php
var_dump(\datadog\appsec\is_fully_disabled());
?>
--EXPECT--
bool(true)
