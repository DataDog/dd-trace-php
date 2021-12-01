--TEST--
Check for ddappsec presence
--FILE--
<?php
if (extension_loaded('ddappsec')) {
    echo "ddappsec extension is available\n";
} else {
    echo "ddappsec COULD NOT BE LOADED\n";
}
?>
--EXPECT--
ddappsec extension is available
