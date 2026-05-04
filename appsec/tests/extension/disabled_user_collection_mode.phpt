--TEST--
Value "invalid" for user collection mode (no warning)
--INI--
error_reporting=2147483647
datadog.appsec.testing=0
--ENV--
DD_APPSEC_AUTO_USER_INSTRUMENTATION_MODE=disabled
--FILE--
<?php
var_dump(ini_get('datadog.appsec.auto_user_instrumentation_mode'));
echo "Done\n";
?>
--EXPECTF--
string(8) "disabled"
Done
