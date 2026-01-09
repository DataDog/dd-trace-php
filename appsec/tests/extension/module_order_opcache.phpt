--TEST--
Verify ddappsec is always in the module registry after ddtrace when opcache is present
--SKIPIF--
<?php
if (version_compare(PHP_VERSION, '8.5.0', '>='))
    die('skip: opcache is loaded by default in PHP 8.5+');

// cover also pre-releases (to remove later)
if (strpos(phpversion(), "8.5") === 0) {
    die('skip: opcache is loaded by default PHP 8.5 (prerelease)');
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
