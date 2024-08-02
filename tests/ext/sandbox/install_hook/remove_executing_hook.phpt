--TEST--
Ensure proper interoperability with multiple observers installed on observer removal
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: observers only exist on PHP 8+'); ?>
<?php if (extension_loaded("zend-test") || extension_loaded("zend_test")) die('skip: zend_test may not be loaded twice'); ?>
<?php if (!file_exists(ini_get("extension_dir") . "/zend_test.so")) die('skip: zend_test is not available'); ?>
--INI--
extension=zend_test
zend_test.observer.enabled=1
zend_test.observer.observe_all=1
--FILE--
<?php

function foo() {
    echo "Called\n";
}

DDTrace\install_hook("foo", function($hook) {
    echo "Removing hook\n";
    DDTrace\remove_hook($hook->id);
});
foo();

?>
--EXPECTREGEX--
<!-- init '.+' -->
<file '.+'>(\n  <!-- init DDTrace\\install_hook\(\) -->\n  <DDTrace\\install_hook>\n  <\/DDTrace\\install_hook>)?
  <!-- init foo\(\) -->
  <foo>
    <!-- init \{closure(:.+:\d)*}\(\) -->
    <\{closure(:.+:\d)*}>
Removing hook(\n      <!-- init DDTrace\\remove_hook\(\) -->\n      <DDTrace\\remove_hook>\n      <\/DDTrace\\remove_hook>)?
    <\/\{closure(:.+:\d)*}>
Called
  <\/foo>
<\/file '.+'>
