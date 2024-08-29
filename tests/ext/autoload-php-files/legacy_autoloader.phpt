--TEST--
Execute the default spl_autoload implementation if spl_autoload_register() is called without args
--SKIPIF--
<?php if (PHP_VERSION_ID >= 80000) die("skip: __autoload was removed in PHP 8") ?>
--INI--
error_reporting=8191
datadog.trace.sources_path="{PWD}/.."
--FILE--
<?php

function __autoload($class) {
    print "Autoload $class attempted!\n";
}

var_dump(class_exists('splautoload'));

echo "Request start" . PHP_EOL;

?>
--EXPECT--
Autoload splautoload attempted!
bool(false)
Request start
