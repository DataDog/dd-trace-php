--TEST--
Settings made via ENV variables take priority over INI ones
--ENV--
DD_APPSEC_LOG_FILE=/env/path
--INI--
datadog.appsec.log_file=/some/path/on/ini
--FILE--
<?php
var_dump(ini_get("datadog.appsec.log_file"));
?>
--EXPECT--
string(9) "/env/path"
