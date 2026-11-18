--TEST--
Check for AppSec presence
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
if (extension_loaded('ddtrace') && function_exists('datadog\appsec\is_enabled')) {
    echo "AppSec is available\n";
} else {
    echo "AppSec IS NOT AVAILABLE\n";
}
if (\datadog\appsec\is_enabled()) {
    echo "AppSec is enabled\n";
} else {
    echo "AppSec is disabled\n";
}
?>
--EXPECT--
AppSec is available
AppSec is enabled
