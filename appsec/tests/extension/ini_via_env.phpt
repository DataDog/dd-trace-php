--TEST--
Settings made via ENV variables
--ENV--
DD_APPSEC_LOG_FILE=/dev/null
--FILE--
<?php
var_dump(ini_get("datadog.appsec.log_file"));
?>
--EXPECT--
string(9) "/dev/null"
