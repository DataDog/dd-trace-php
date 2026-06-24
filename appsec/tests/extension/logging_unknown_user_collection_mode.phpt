--TEST--
Warning is emitted at startup for unknown DD_APPSEC_AUTO_USER_INSTRUMENTATION_MODE value
--INI--
error_reporting=2147483647
datadog.appsec.testing=0
--ENV--
DD_APPSEC_AUTO_USER_INSTRUMENTATION_MODE=invalid
--FILE--
<?php
echo "Done\n";
?>
--EXPECTF--
%AWarning:%a[ddappsec] Unknown user collection mode: invalid%a
Done
