--TEST--
Verify ddappsec is always in the module registry after ddtrace when opcache is present
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) {
    die('skip requires opcache');
}
?>
--INI--
extension=ddtrace.so
zend_extension=opcache.so
--FILE--
<?php
foreach (get_loaded_extensions() as &$ext) {
    if ($ext == 'ddappsec' || $ext == 'ddtrace') {
        printf("%s\n", $ext);
    }
}
?>
--EXPECTF--
ddtrace
ddappsec
