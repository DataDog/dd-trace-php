--TEST--
Check for ddappsec presence
--INI--
datadog.appsec.enabled_on_cli=1
--FILE--
<?php
if (extension_loaded('ddappsec')) {
    echo "ddappsec extension is available\n";
} else {
    echo "ddappsec COULD NOT BE LOADED\n";
}
if (\datadog\appsec\is_enabled()) {
    echo "ddappsec extension is enabled\n";
} else {
    echo "ddappsec extension is disabled\n";
}
?>
--EXPECT--
ddappsec extension is available
ddappsec extension is enabled
