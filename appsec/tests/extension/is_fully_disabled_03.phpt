--TEST--
is_fully_disabled returns false when DD_APPSEC_ENABLED not set and remote config enabled (not fully disabled)
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
--ENV--
DD_REMOTE_CONFIG_ENABLED=1
--FILE--
<?php
var_dump(\datadog\appsec\is_fully_disabled());
?>
--EXPECT--
bool(false)
