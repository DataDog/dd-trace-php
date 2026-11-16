--TEST--
Test extension is disabled if not configured explicitly and rc set to disabled
--INI--
datadog.remote_config_enabled=0
--FILE--
<?php
var_dump(\datadog\appsec\is_enabled());
?>
--EXPECTF--
bool(false)
